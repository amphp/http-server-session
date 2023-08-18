<?php

use Amp\Http\Server\Session\Session;
use Amp\Http\Server\Session\TestSession;
use PHPUnit\Framework\TestCase;

class TestSessionTest extends TestCase
{
    private TestSession $testSession;

    protected function setUp(): void
    {
        $this->testSession = new TestSession();
        $this->testSession->givenSession('a', [
            'foo' => 42,
            'bar' => false,
        ]);
    }

    public function testReadCount()
    {
        $this->handle('a', function (Session $session) {
            self::assertSame(42, $session->get('foo'));
        });

        self::assertSame(1, $this->testSession->getReadCount('a'));
        self::assertSame(0, $this->testSession->getReadCount('b'));

        $this->handle('a', function (Session $session) {
            $session->read();
            $session->read();
        });

        self::assertSame(3, $this->testSession->getReadCount('a'));
    }

    public function testWriteCount()
    {
        $this->handle('a', function (Session $session) {
            $session->lock();
            $session->commit();
        });

        self::assertSame(1, $this->testSession->getWriteCount('a'));
        self::assertSame(0, $this->testSession->getWriteCount('b'));
    }

    public function testLockCount()
    {
        $this->handle('a', function (Session $session) {
            $session->lock();
            $session->commit();
        });

        self::assertSame(1, $this->testSession->getLockCount('a'));
        self::assertSame(0, $this->testSession->getLockCount('b'));

        $this->handle('a', function (Session $session) {
            $session->lock();
        });

        self::assertSame(2, $this->testSession->getLockCount('a'));
        self::assertTrue($this->testSession->isLocked('a'));
        self::assertFalse($this->testSession->isLocked('b'));
    }

    public function testAddedKey()
    {
        $this->handle('a', function (Session $session) {
            $session->lock();
            $session->set('baz', 'now');
            $session->commit();
        });

        self::assertContains('baz', $this->testSession->getAddedKeys('a'));
        self::assertEmpty($this->testSession->getAddedKeys('b'));
    }

    public function testRemovedKey()
    {
        $this->handle('a', function (Session $session) {
            $session->lock();
            $session->unset('foo');
            $session->commit();
        });

        self::assertContains('foo', $this->testSession->getRemovedKeys('a'));
        self::assertEmpty($this->testSession->getRemovedKeys('b'));
    }

    private function handle(string $id, \Closure $closure): void
    {
        $session = $this->testSession->getFactory()->create($id);
        $closure($session);
    }
}
