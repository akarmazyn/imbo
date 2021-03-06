<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace Imbo;

use Imbo\Image\Transformation,
    Imbo\Cache,
    Imbo\Resource\ResourceInterface,
    Imbo\EventManager\EventInterface,
    Imbo\EventListener\ListenerInterface,
    Imbo\Model\ArrayModel,
    Memcached as PeclMemcached,
    PHPUnit_Framework_MockObject_Generator,
    PHPUnit_Framework_MockObject_Matcher_AnyInvokedCount,
    PHPUnit_Framework_MockObject_Stub_Return;

// Require composer autoloader
require __DIR__ . '/../vendor/autoload.php';

class CustomResource implements ResourceInterface {
    public function getAllowedMethods() {
        return array('GET');
    }

    public static function getSubscribedEvents() {
        return array(
            'custom1.get' => 'get',
        );
    }

    public function get(EventInterface $event) {
        $model = new ArrayModel();
        $model->setData(array(
            'event' => $event->getName(),
            'id' => $event->getRequest()->getRoute()->get('id'),
        ));
        $event->getResponse()->setModel($model);
    }
}

class CustomResource2 implements ResourceInterface {
    public function getAllowedMethods() {
        return array('GET', 'PUT');
    }

    public static function getSubscribedEvents() {
        return array(
            'custom2.get' => 'get',
            'custom2.put' => 'put',
        );
    }

    public function get(EventInterface $event) {
        $model = new ArrayModel();
        $model->setData(array(
            'event' => $event->getName(),
        ));
        $event->getResponse()->setModel($model);
    }

    public function put(EventInterface $event) {
        $model = new ArrayModel();
        $model->setData(array('event' => $event->getName()));
        $event->getResponse()->setModel($model);
    }
}

class CustomEventListener implements ListenerInterface {
    private $value1;
    private $value2;

    public function __construct($value1, $value2) {
        $this->value1 = $value1;
        $this->value2 = $value2;
    }

    public static function getSubscribedEvents() {
        return array(
            'index.get' => 'getIndex',
            'user.get' => 'getUser',
        );
    }

    public function getIndex(EventManager\EventInterface $event) {
        $event->getResponse()->headers->add(array(
            'X-Imbo-Value1' => $this->value1,
            'X-Imbo-Value2' => $this->value2,
        ));
    }

    public function getUser(EventManager\EventInterface $event) {
        $event->getResponse()->headers->set('X-Imbo-CurrentUser', $event->getRequest()->getPublicKey());
    }
}

$statsWhitelist = array('127.0.0.1', '::1');
$statsBlacklist = array();

if (!empty($_GET['statsWhitelist'])) {
    // The scenario has specified a whitelist to use
    $statsWhitelist = explode(',', $_GET['statsWhitelist']);
}

if (!empty($_GET['statsBlacklist'])) {
    // The scenario has specified a blacklist to use
    $statsBlacklist = explode(',', $_GET['statsBlacklist']);

    if (empty($_GET['statsWhitelist'])) {
        $statsWhitelist = array();
    }
}

if (isset($_SERVER['HTTP_X_CLIENT_IP'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_CLIENT_IP'];
}

return array(
    'auth' => array(
        'publickey' => 'privatekey',
        'user' => 'key',
    ),

    'database' => function() {
        $adapter = PHPUnit_Framework_MockObject_Generator::getMock(
            'Imbo\Database\MongoDB',
            array('getStatus'),
            array(array('databaseName' => 'imbo_testing'))
        );

        $adapter->expects(new PHPUnit_Framework_MockObject_Matcher_AnyInvokedCount())
                ->method('getStatus')
                ->will(new PHPUnit_Framework_MockObject_Stub_Return(isset($_GET['databaseDown']) ? false : true));

        return $adapter;
    },

    'storage' => function() {
        $adapter = PHPUnit_Framework_MockObject_Generator::getMock(
            'Imbo\Storage\GridFS',
            array('getStatus'),
            array(array('databaseName' => 'imbo_testing'))
        );

        $adapter->expects(new PHPUnit_Framework_MockObject_Matcher_AnyInvokedCount())
                ->method('getStatus')
                ->will(new PHPUnit_Framework_MockObject_Stub_Return(isset($_GET['storageDown']) ? false : true));

        return $adapter;
    },

    'eventListeners' => array(
        'auth' => 'Imbo\EventListener\Authenticate',
        'accessToken' => 'Imbo\EventListener\AccessToken',
        'statsAccess' => array(
            'listener' => 'Imbo\EventListener\StatsAccess',
            'params' => array(
                array(
                    'whitelist' => $statsWhitelist,
                    'blacklist' => $statsBlacklist,
                )
            ),
        ),
        'imageTransformationCache' => array(
            'listener' => 'Imbo\EventListener\ImageTransformationCache',
            'params' => array('/tmp/imbo-behat-image-transformation-cache'),
        ),
        'metadataCache' => function() {
            $memcached = new PeclMemcached();
            $memcached->addServer('localhost', 11211);

            return new EventListener\MetadataCache(new Cache\Memcached($memcached));
        },
        'someHandler' => array(
            'events' => array(
                'index.get' => 1000,
            ),
            'callback' => function(EventManager\EventInterface $event) {
                $event->getResponse()->headers->set('X-Imbo-SomeHandler', microtime(true));
            }
        ),
        'someOtherHandler' => array(
            'events' => array(
                'index.get',
                'index.head',
            ),
            'callback' => function(EventManager\EventInterface $event) {
                $event->getResponse()->headers->set('X-Imbo-SomeOtherHandler', microtime(true));
            },
            'priority' => 10,
        ),
        'someEventListener' => array(
            'listener' => __NAMESPACE__ . '\CustomEventListener',
            'params' => array(
                'value1', 'value2'
            ),
            'publicKeys' => array(
                'whitelist' => array('publickey'),
            ),
        ),
        'cors' => array(
            'listener' => 'Imbo\EventListener\Cors',
            'params' => array(
                array(
                    'allowedOrigins' => array('http://allowedhost'),
                    'maxAge' => 1349,
                ),
            ),
        ),
        'maxImageSize' => array(
            'listener' => 'Imbo\EventListener\MaxImageSize',
            'params' => array(1000, 1000),
        ),
        'varnishHashTwo' => 'Imbo\EventListener\VarnishHashTwo',
        'customVarnishHashTwo' => array(
            'listener' => 'Imbo\EventListener\VarnishHashTwo',
            'params' => array('X-Imbo-HashTwo'),
        ),
    ),

    'imageTransformations' => array(
        'border' => function (array $params) {
            return new Transformation\Border($params);
        },
        'canvas' => function (array $params) {
            return new Transformation\Canvas($params);
        },
        'compress' => function (array $params) {
            return new Transformation\Compress($params);
        },
        'convert' => function (array $params) {
            return new Transformation\Convert($params);
        },
        'crop' => function (array $params) {
            return new Transformation\Crop($params);
        },
        'desaturate' => function (array $params) {
            return new Transformation\Desaturate();
        },
        'flipHorizontally' => function (array $params) {
            return new Transformation\FlipHorizontally();
        },
        'flipVertically' => function (array $params) {
            return new Transformation\FlipVertically();
        },
        'maxSize' => function (array $params) {
            return new Transformation\MaxSize($params);
        },
        'resize' => function (array $params) {
            return new Transformation\Resize($params);
        },
        'rotate' => function (array $params) {
            return new Transformation\Rotate($params);
        },
        'sepia' => function (array $params) {
            return new Transformation\Sepia($params);
        },
        'thumbnail' => function (array $params) {
            return new Transformation\Thumbnail($params);
        },
        'transpose' => function (array $params) {
            return new Transformation\Transpose();
        },
        'transverse' => function (array $params) {
            return new Transformation\Transverse();
        },

        // collection
        'graythumb' => function (array $params) {
            return new Transformation\Collection(array(
                new Transformation\Thumbnail($params),
                new Transformation\Desaturate(),
            ));
        }
    ),

    'routes' => array(
        'custom1' => '#^/custom/(?<id>[a-zA-Z0-9]{7})$#',
        'custom2' => '#^/custom(?:\.(?<extension>json|xml))?$#',
    ),

    'resources' => array(
        'custom1' => __NAMESPACE__ . '\CustomResource',
        'custom2' => function() {
            return new CustomResource2();
        }
    ),
);
