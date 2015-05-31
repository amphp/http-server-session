<?php

namespace Aerys;

use Amp\{
    Promise,
    Success,
    function pipe
};

class Session implements \ArrayAccess {
    const CONFIG = [
        "name" => "AerysSessionId",
        "ttl" => -1,
    ];

    private $request;
    private $driver;
    private $id; // usually _the id_, false when expired (empty session data), null when not set at all
    private $data;
    private $writable = false;
    private $readPipe;
    private $defaultPipe;

    const ALLOWED_ID_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    const ID_LENGTH = 24; // divisible by three to not waste chars with "="

    public function  __construct(Request $request) {
        $this->readPipe = function(array $data) {
            if (empty($data)) {
                $this->setId(false);
            }
            $this->data = $data;
            return $this;
        };
        $this->defaultPipe = function() {
            return $this;
        };

        $this->request = $request;
        $config = $request->getLocalVar("aerys.session.config");
        $this->driver = $config["driver"];

        $config += static::CONFIG;
        $request->setLocalVar("aerys.session.config", $config);

        $id = $request->getCookie($config["name"]);
        if (\strlen($id) == self::ID_LENGTH && strspn($id, self::ALLOWED_ID_CHARS) == self::ID_LENGTH) {
            $this->setId($id);
        }
    }


    private function generateId() {
        return base64_encode(random_bytes(self::ID_LENGTH));
    }

    private function setId($id) {
        $this->id = $id;
        $this->request->setLocalVar("aerys.session.id", $id);
    }

    /**
     * Set a TTL (in seconds), so that the session expires after that time
     *
     * @param int $ttl sets a ttl, -1 to disable it [means: cookie persists until browser close]
     */
    public function setTTL(int $ttl) {
        $config = $this->request->getLocalVar("aerys.session.config");
        $config["ttl"] = $ttl;
        $this->request->setLocalVar("aerys.session.config", $config);
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet($offset) {
        if (!array_key_exists($offset, $this->data)) {
            throw new \Exception("Key '$offset' does not exist in session");
        }

        return $this->data[$offset];
    }

    public function offsetSet($offset, $value) {
        if (!$this->writable) {
            throw new Session\LockException("Session is not locked, can't write");
        }

        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset) {
        unset($this->data[$offset]);
    }

    /**
     * Creates a lock and reads the current session data
     * @return \Amp\Promise resolving after success
     */
    public function open(): Promise {
        if ($this->writable) {
            throw new Session\LockException("Session already opened, can't open again");
        }

        $this->writable = true;
        return !$this->id ? new Success($this) : pipe($this->driver->open($this->id), $this->readPipe)->when(function ($e) {
            if ($e) {
                $this->writable = false;
            }
        });
    }

    /**
     * Saves and unlocks a session
     * @return \Amp\Promise resolving after success
     */
    public function save(): Promise {
        if (!$this->writable) {
            throw new Session\LockException("Session is not locked, can't write");
        }

        $this->writable = false;
        if (!$this->id && $this->data) {
            $this->setId($this->generateId());
        }
        return pipe($this->driver->save($this->id, $this->data), $this->defaultPipe);
    }

    /**
     * Reloads the session contents and locks
     * @return \Amp\Promise resolving after success
     */
    public function read(): Promise {
        if ($this->writable) {
            throw new Session\LockException("Session is locked, can't read in locked state; use the return value of the call to \\Aerys\\Session::open()");
        }

        return $this->id === null ? new Success($this) : pipe($this->driver->read($this->id), $this->readPipe);
    }

    /**
     * Unlocks the session, reloads data without saving
     * @return \Amp\Promise resolving after success
     */
    public function unlock(): Promise {
        if (!$this->writable) {
            throw new Session\LockException("Session is not locked, can't write");
        }

        $this->writable = false;
        if ($this->id) {
            return pipe($this->driver->unlock(), function () {
                return pipe($this->config["driver"]->read($this->id), $this->readPipe);
            });
        } else {
            $this->data = [];
            return new Success($this);
        }
    }

    /**
     * Regenerates a session id
     * @return \Amp\Promise resolving after success
     */
    public function regenerate(): Promise {
        if (!$this->writable) {
            throw new Session\LockException("Session is not locked, can't write");
        }

        if ($this->id) {
            $new = $this->generateId();
            $promise = $this->driver->regenerate($this->id, $new);
            $this->setId($new);
            return pipe($promise, $this->defaultPipe);
        } else {
            return new Success($this);
        }
    }

    /**
     * Destroys the session
     * @return \Amp\Promise resolving after success
     */
    public function destroy(): Promise {
        if (!$this->writable) {
            throw new Session\LockException("Session is not locked, can't destroy");
        }

        if ($this->id) {
            $promise = $this->driver->destroy($this->id);
            $this->setId(false);
            $this->data = [];
            $this->writable = false;
            return pipe($promise, $this->defaultPipe);
        } else {
            return new Success($this);
        }
    }

    public function __destruct() {
        if ($this->writable) {
            $this->save();
        }
    }
}
