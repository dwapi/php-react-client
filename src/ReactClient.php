<?php

namespace Wapi\ReactClient;

use Clue\React\Buzz\Browser;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Timer\TimerInterface;
use React\Promise\Deferred;

/**
 * Provides the React Client.
 */
class ReactClient {
  
  /**
   * @var static
   */
  protected static $instance;
  
  /**
   * React loop.
   * @var \React\EventLoop\LoopInterface
   */
  protected $loop;
  
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
  }
  
  static function getInstance() {
    if(!static::$instance) {
      static::$instance = new static();
    }
    
    return static::$instance;
  }
  
  public function getLoop(){
    return $this->loop = $this->loop ?: \React\EventLoop\Factory::create();
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
          $that->stopLoop();
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
  
  public function stopLoop() {
    if($this->loopStarted) {
      if($this->loopTimeout) {
        $this->clearTimeout($this->loopTimeout);
      }
      
      $this->loopStarted = false;
      $this->getLoop()->stop();
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
    $connector = new Connector($this->getLoop());
    $promise = $connector($address, [], []);
    
    $promise->then($this->appendThisToCallbackArgs($success_callback), $this->appendThisToCallbackArgs($fail_callback));
    
    return $promise;
  }
  
  public function wsDisconnect(WebSocket $conn = NULL) {
    if($conn) {
      $conn->close();
    }
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
