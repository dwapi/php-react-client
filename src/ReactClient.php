<?php

namespace Wapi\ReactClient;

use Clue\React\Buzz\Browser;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Timer\TimerInterface;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Wapi\Protocol\Exception\ApplicationError;
use Wapi\Protocol\Exception\CommunicationError;
use Wapi\Protocol\Exception\WapiProtocolException;
use Wapi\Protocol\Protocol;

/**
 * Provides the React Client.
 */
class ReactClient {
  
  /**
   * @var static
   */
  protected static $instance = NULL;
  
  /**
   * React loop.
   * @var \React\EventLoop\LoopInterface
   */
  protected $loop;
  
  /**
   * @var WebSocket[]
   */
  protected $connections = [];
  
  /**
   * Loop running state.
   *
   * @var bool
   */
  protected $loopStarted = FALSE;
  
  /**
   * Loop timeout handler.
   *
   * @var TimerInterface
   */
  protected $loopTimeout;
  
  /**
   * Callbacks to run on loop ending.
   *
   * @var callable[]
   */
  protected $onLoopEndCallbacks = [];
  
  /**
   * @var \Clue\React\Buzz\Browser
   */
  protected $browser;
  
  /**
   * Constructs a ReactClient object.
   */
  public function __construct() {
    $this->loop = \React\EventLoop\Factory::create();
  }
  
  static function getInstance() {
    if (!static::$instance) {
      static::$instance = new static();
    }
    
    return static::$instance;
  }
  
  public function getLoop(){
    return $this->loop;
  }
  
  public function getAsyncHttpClient() {
    return $this->browser = $this->browser ?: new Browser($this->getLoop());
  }
  
  public function startReactor($timeout = NULL, callable $timeout_callback = NULL) {
    
    if(!$this->loopStarted) {
      if($timeout) {
        $that = $this;
        $callback = function() use ($timeout_callback, $that) {
          $that->onLoopEnd($timeout_callback);
          $that->stopReactor();
          foreach($this->onLoopEndCallbacks AS $savedCallback) {
            call_user_func($that->appendThisToCallbackArgs($savedCallback));
          }
        };
        $this->loopTimeout = $this->setTimeout($callback, $timeout);
      }
      
      $this->loopStarted = TRUE;
      $this->getLoop()->run();
    }
  }
  
  public function inLoop() {
    return $this->loopStarted;
  }
  
  public function onLoopEnd(callable $callback = NULL) {
    if($callback) {
      $this->onLoopEndCallbacks[] = $callback;
    }
  }
  
  public function stopReactor() {
    
    if($this->loopStarted) {
      if($this->loopTimeout) {
        $this->clearTimeout($this->loopTimeout);
      }
      
      $this->loopStarted = false;
      $this->getLoop()->stop();
      
      $this->onLoopEndCallbacks = [];
      
      foreach($this->connections AS $address => $conn) {
        $this->wsDisconnect($address);
      }
    }
  }
  
  public function setTimeout(callable $callback, $time = 0) {
    return $this->getLoop()->addTimer($time, $this->appendThisToCallbackArgs($callback));
  }
  
  public function clearTimeout(TimerInterface $timer) {
    $this->getLoop()->cancelTimer($timer);
  }
  
  public function newDeferred() {
    return new Deferred();
  }
  
  public function wsConnect($address, $success_callback = NULL, $fail_callback = NULL) {
    $that = $this;
    if(!empty($this->connections[$address])) {
      $promise = new FulfilledPromise($this->connections[$address]);
    } else {
      $connector = new Connector($this->getLoop());
      $promise = $connector($address, [], []);
      
      $promise->then(function(WebSocket $conn) use ($address, $that) {
        $that->connections[$address] = $conn;
        
        $conn->on('close', function () use ($that, $address) {
          unset($that->connections[$address]);
        });
      });
    }
    $promise->then($this->appendThisToCallbackArgs($success_callback), $this->appendThisToCallbackArgs($fail_callback));
    
    return $promise;
  }
  
  public function wsDisconnect($address) {
    if(!empty($this->connections[$address])) {
      $this->connections[$address]->close();
      unset($this->connections[$address]);
    }
  }
  
  public function wsSend($address, $secret, $method, $data, $clock_offset = 0, $additional = []) {
    $result = $this->newDeferred();
    
    $this->wsConnect($address, function (WebSocket $conn, ReactClient $react_client) use ($result, $secret, $method, $data, $clock_offset, $additional) {
      $body = Protocol::buildMessage($secret, $method, $data, $additional, $clock_offset);
      $message_id = $body['message_id'];
      $conn->send(Protocol::encode($body));
      
      $conn->on('message', function ($msg) use ($conn, $react_client, $message_id, $result) {
        $response = Protocol::decode($msg);
        if (!empty($response['message_id']) && $response['message_id'] === $message_id) {
          if (empty($response['status'])) {
            $result->resolve($response['data']);
          }
          else {
            $result->reject(new ApplicationError($response['error']));
          }
        }
      });
      
      $conn->on('close', function () use ($result) {
        $result->reject(new ApplicationError("Connection closed."));
      });
      
      $timer = $react_client->setTimeout(function () use ($result) {
        $result->reject(new CommunicationError("Response timed out."));
      }, 4);
      
      $result->promise()->always(function () use ($timer, $react_client) {
        $react_client->clearTimeout($timer);
      });
    }, function(\Exception $e) use ($result) {
      $result->reject(new CommunicationError($e->getMessage()));
    });
    
    return $result->promise();
  }

    /**
     * @param PromiseInterface $promise
     * @param null $timeout
     * @return mixed
     * @throws \Exception
     */
  public function wait(PromiseInterface $promise, $timeout = NULL) {

    $promises = [$promise];
    if ($timeout) {
        $promises[] = $this->setTimeout(function () {
            throw new \Exception('Timed out');
        }, $timeout);
    }

    return \Clue\React\Block\awaitAny($promises, $this->getLoop());
  }
  
  public function waitBool(PromiseInterface $promise, $timeout = NULL) {
    try {
      $this->wait($promise, $timeout);
      return TRUE;
    } catch (\Exception $e) {
      return FALSE;
    }
  }
  
  public function passPromise(PromiseInterface $promise, Deferred $pass_to) {
    $promise->then(function ($result) use ($pass_to) {
      $pass_to->resolve($result);
    }, function (\Exception $e) use ($pass_to) {
      $pass_to->reject($e);
    });
  }
  
  private function appendThisToCallbackArgs(callable $callback = NULL) {
    if(!$callback) {
      return $callback;
    }
    $that = $this;
    $c = function () use ($that, $callback) {
      $args = func_get_args();
      $args[] = $that;
      call_user_func_array($callback, $args);
    };
    return $c;
  }
  
}
