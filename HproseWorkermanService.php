<?php
/**********************************************************\
|                                                          |
|                          hprose                          |
|                                                          |
| Official WebSite: http://www.hprose.com/                 |
|                   http://www.hprose.org/                 |
|                                                          |
\**********************************************************/

/**********************************************************\
 *                                                        *
 * HproseWorkermanService.php                             *
 *                                                        *
 * hprose service class for php 5.3+                      *
 * This client version supports the Workerman functions.  *
 *                                                        *
 * LastModified: Oct 28, 2015                             *
 * Author: Kevin Ingwersen <ingwie2000@gmail.com>         *
 *         http://ingwie.me                               *
 *                                                        *
\**********************************************************/


/**
 * @file
 * This file contains functionality to hook into the Workerman system.
 */

/**
 * This class manages the mangling and de-mangling of hprose data.
 * It strips or assigns the length as needed.
 */
namespace Workerman\Protocols {

    use \Workerman\Connection\ConnectionInterface as Connection;

    class Hdp implements ProtocolInterface {
        private static function getLen($buf) {
            if(strlen($buf) <= 4) {
                return strlen($buf);
            }
            // The first four bytes determine the length.
            $data = substr($buf, 0, 4);
            $raw = unpack("Nlen", $data);
            // The 4 bytes /must/ be present.
            // Actually, I could also use strpos($buf, "z")+1 for the length... But this has to be tested!
            return $raw["len"]+4;
        }
        public static function input($buf, Connection $conn) {
            return self::getLen($buf);
        }
        public static function encode($buf, Connection $conn) {
            $len = strlen($buf);
            $raw = pack("N", $len);
            return $raw.$buf;
        }
        public static function decode($buf, Connection $conn) {
            // Oh god, this looks so cheaty. Please, pretty please, FIXME.
            if(strlen($buf) <= 4) {
                return $buf;
            } else {
                return substr($buf, 4, self::getLen($buf));
            }
        }
    }

}

/**
 * This is a soft wrapper around the \Hprose\Service class. It accepts \Workerman\Worker
 * derived classes and provides access into the hprose system.
 * It could be considered an abstraction.
 *
 * This class is ment to be used only internally. It should not be used by
 * anything else but WorkermanHprose.
 */
namespace Bridge {

    if(!class_exists("\Hprose\Base\Service")) {
        require_once __DIR__."/Service.php";
    }

    use \Workerman\Worker;
    use \Hprose\Base\Service;
    use \stdClass;

    class HproseWorkermanService extends Service {
        private $worker;
        public $ctx;
        public function __construct(Worker &$worker) {
            $self = $this;
            $this->user_fatal_error_handler = function($log) use($self){
                $self->ctx->conn->send($log);
            };
            parent::__construct();
            $this->worker = $worker;
            $this->ctx = new stdClass;
        }
        public function handle(&$conn, $request) {
            $this->ctx->conn = $conn;
            $conn->send($this->defaultHandle($request, $this->ctx));
        }
    }

}

/**
 * This is the actual class that provides the bindings.
 * It overrides the onMessage callback to handle it with hprose.
 * It provides a method to access a reference of the original hprose
 * instance and also a shorthand method to add functions/class methods.
 * It is recommended to use the actual hprose api.
 *
 * An example of how it is used:
 *
 * ```php
 * <?php
 * include "hprose-php/Hprose.php";
 * include "Workerman/Autoloader.php";
 *
 * function hello($w) { return "Hello, $w!"; }
 *
 * $client = new \Workerman\Hprose("127.0.0.1", 9999);
 * $client->count = 4; # Make 4 workers.
 * $hprose = $client->hprose();
 * $hprose->addFunction("hello");
 *
 * Worker::runAll();
 * ?>
 * ```
 *
 * From now on, there is a server on localhost:9999, ready to take hprose commands!
 */
namespace Workerman {

    use \Workerman\Worker;
    use \Bridge\HproseWorkermanService as HproseWorkermanService;

    class Hprose extends Worker {
        // Initialize
        private $_hprose;
        public function __construct($host, $port, $opts = array()) {
            parent::__construct("hdp://{$host}:{$port}", $opts);
            $this->name = "hprose";
            $this->_hprose = new HproseWorkermanService($this);
        }

        public function &hprose() { return $this->_hprose; }

        // Setup the methods
        public function run() {
            $this->onMessage = array($this, 'onMessage');
            parent::run();
        }

        // The handler
        public function onMessage($conn, $data) {
            $this->_hprose->handle($conn, $data);
        }

        // Adding functions to hprose... in a cheaty way.
        public function add($name, $fnc) {
            if(is_string($name) && (is_callable($fnc) || is_array($fnc))) {
                return $this->_hprose->addFunction($fnc, $name);
            }
        }
    }

}
