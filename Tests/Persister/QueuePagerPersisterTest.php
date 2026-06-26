<?php

namespace Enqueue\ElasticaBundle\Tests\Persister;

use Enqueue\ElasticaBundle\Persister\QueuePagerPersister;
use FOS\ElasticaBundle\Elastica\Index;
use FOS\ElasticaBundle\Index\IndexManager;
use FOS\ElasticaBundle\Persister\Event\PostPersistEvent;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use FOS\ElasticaBundle\Persister\PersisterRegistry;
use FOS\ElasticaBundle\Provider\PagerInterface;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Producer;
use Interop\Queue\Queue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class QueuePagerPersisterTest extends TestCase
{
    /**
     * Regression guard for the idle-timeout behaviour: as long as replies keep arriving, insert()
     * must finish even when the configured timeout is tiny relative to the overall run. With the
     * former hard "overall reply time" cap this threw once the cap elapsed; now the deadline is reset
     * on every reply, so a steady stream of replies always completes.
     */
    public function testInsertCompletesWhileRepliesKeepArrivingDespiteTinyTimeout(): void
    {
        $pages = 5;

        $reply = $this->createMock(Message::class);
        $reply->method('getBody')->willReturn((string) json_encode([
            'page'    => 1,
            'options' => ['indexName' => 'statements'],
        ]));
        $reply->method('getProperty')->willReturn(false);

        $consumer = $this->createMock(Consumer::class);
        // One reply per sent page; the deadline is reset on each, so the (tiny) timeout never fires.
        $consumer->method('receive')->willReturnOnConsecutiveCalls(
            $reply, $reply, $reply, $reply, $reply
        );

        $dispatcher = new EventDispatcher();
        $completed = false;
        $dispatcher->addListener(PostPersistEvent::class, static function () use (&$completed) {
            $completed = true;
        });

        $sut = new QueuePagerPersister(
            $this->createContext($consumer),
            $this->createRegistry(),
            $dispatcher,
            $this->createIndexManager()
        );

        $sut->insert($this->createPager($pages), [
            'indexName'               => 'statements',
            'limit_overall_reply_time' => 0.0001,
            'reply_receive_timeout'    => 0,
        ]);

        self::assertTrue($completed, 'insert() should finish and dispatch PostPersistEvent without throwing.');
    }

    /**
     * When no reply arrives within the idle window the consumers are assumed stuck and insert() aborts.
     */
    public function testInsertThrowsWhenNoReplyArrivesWithinIdleWindow(): void
    {
        $consumer = $this->createMock(Consumer::class);
        $consumer->method('receive')->willReturn(null);

        $sut = new QueuePagerPersister(
            $this->createContext($consumer),
            $this->createRegistry(),
            new EventDispatcher(),
            $this->createIndexManager()
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/appear to be stuck/');

        $sut->insert($this->createPager(5), [
            'indexName'               => 'statements',
            'limit_overall_reply_time' => 0,
            'reply_receive_timeout'    => 0,
        ]);
    }

    private function createContext(Consumer $consumer): Context
    {
        $replyQueue = $this->createMock(Queue::class);
        $replyQueue->method('getQueueName')->willReturn('reply-queue');

        $context = $this->createMock(Context::class);
        $context->method('createQueue')->willReturn($this->createMock(Queue::class));
        $context->method('createTemporaryQueue')->willReturn($replyQueue);
        $context->method('createProducer')->willReturn($this->createMock(Producer::class));
        $context->method('createConsumer')->willReturn($consumer);
        $context->method('createMessage')->willReturn($this->createMock(Message::class));

        return $context;
    }

    private function createRegistry(): PersisterRegistry
    {
        $registry = $this->createMock(PersisterRegistry::class);
        $registry->method('getPersister')->willReturn($this->createMock(ObjectPersisterInterface::class));

        return $registry;
    }

    private function createIndexManager(): IndexManager
    {
        $index = $this->createMock(Index::class);
        $index->method('getName')->willReturn('statements');
        $index->method('getOriginalName')->willReturn('statements');

        $indexManager = $this->createMock(IndexManager::class);
        $indexManager->method('getIndex')->willReturn($index);

        return $indexManager;
    }

    private function createPager(int $pages): PagerInterface
    {
        $pager = $this->createMock(PagerInterface::class);
        $pager->method('getMaxPerPage')->willReturn(100);
        $pager->method('getNbPages')->willReturn($pages);
        $pager->method('getCurrentPage')->willReturn(1);

        return $pager;
    }
}
