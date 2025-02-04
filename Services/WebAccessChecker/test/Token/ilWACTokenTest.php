<?php
/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

// declare(strict_types=1);
require_once('./libs/composer/vendor/autoload.php');

require_once('./Services/WebAccessChecker/classes/class.ilWACSignedPath.php');
require_once('./Services/WebAccessChecker/classes/class.ilWebAccessChecker.php');
require_once('./Services/WebAccessChecker/classes/class.ilWACSignedPath.php');
require_once('./Services/WebAccessChecker/classes/class.ilWACToken.php');

use ILIAS\HTTP\Cookies\Cookie;
use ILIAS\HTTP\Cookies\CookieFactory;
use ILIAS\HTTP\Cookies\CookieFactoryImpl;
use ILIAS\HTTP\Cookies\CookieJar;
use ILIAS\HTTP\GlobalHttpState;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery\MockInterface;
use org\bovigo\vfs;
use Psr\Http\Message\ResponseInterface;
use ILIAS\HTTP\Cookies\CookieWrapper;
use Dflydev\FigCookies\SetCookie;

/**
 * TestCase for the ilWACTokenTest
 *
 * @author                 Fabian Schmid <fs@studer-raimann.ch>
 * @version                1.0.0
 *
 * @preserveGlobalState    disabled
 * @backupGlobals          disabled
 * @backupStaticAttributes disabled
 */
class ilWACTokenTest extends MockeryTestCase
{
    const ADDITIONAL_TIME = 1;
    const LIFETIME = 2;
    const SALT = 'SALT';
    const CLIENT_NAME = 'client_name';
    /**
     * @var bool
     */
    protected $backupGlobals = false;
    /**
     * @var vfs\vfsStreamFile
     */
    protected $file_one;
    /**
     * @var vfs\vfsStreamFile
     */
    protected $file_one_subfolder;
    /**
     * @var vfs\vfsStreamFile
     */
    protected $file_one_subfolder_two;
    /**
     * @var vfs\vfsStreamFile
     */
    protected $file_two;
    /**
     * @var vfs\vfsStreamFile
     */
    protected $file_three;
    /**
     * @var vfs\vfsStreamFile
     */
    protected $file_four;
    /**
     * @var vfs\vfsStreamDirectory
     */
    protected $root;
    /**
     * @var GlobalHttpState|MockInterface $http
     */
    private $http;
    /**
     * @var CookieFactory|MockInterface $cookieFactory
     */
    private $cookieFactory;


    /**
     * Setup
     */
    protected function setUp() : void
    {
        parent::setUp();

        $this->root = vfs\vfsStream::setup('ilias.de');
        $this->file_one = vfs\vfsStream::newFile('data/client_name/mobs/mm_123/dummy.jpg')
                                       ->at($this->root)->setContent('dummy');
        $this->file_one_subfolder = vfs\vfsStream::newFile('data/client_name/mobs/mm_123/mobile/dummy.jpg')
                                                 ->at($this->root)->setContent('dummy');
        $this->file_one_subfolder_two = vfs\vfsStream::newFile('data/client_name/mobs/mm_123/mobile/device/dummy.jpg')
                                                     ->at($this->root)->setContent('dummy');
        $this->file_two = vfs\vfsStream::newFile('data/client_name/mobs/mm_123/dummy2.jpg')
                                       ->at($this->root)->setContent('dummy2');
        $this->file_three = vfs\vfsStream::newFile('data/client_name/mobs/mm_124/dummy.jpg')
                                         ->at($this->root)->setContent('dummy');
        $this->file_four = vfs\vfsStream::newFile('data/client_name/sec/ilBlog/mm_124/dummy.jpg')
                                        ->at($this->root)->setContent('dummy');

        //setup container for HttpServiceAware classes
        $container = new \ILIAS\DI\Container();
        $container['http'] = function ($c) {
            return Mockery::mock(GlobalHttpState::class);
        };

        $this->http = $container['http'];


        $GLOBALS["DIC"] = $container;

        $this->cookieFactory = Mockery::mock(CookieFactoryImpl::class);

        //because the cookie have no logic except cloning it self therefore it should be no problem to defer the function calls
        $this->cookieFactory->shouldDeferMissing();

        ilWACToken::setSALT(self::SALT);
    }


    public function testWithoutSigning()
    {
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->file_one->url(), false), $this->http, $this->cookieFactory);

        $cookieJar = Mockery::mock(CookieJar::class);

        $cookieJar
            ->shouldReceive('getAll')
            ->times(2)
            ->withAnyArgs()
            ->andReturn([]);

        $this->http->shouldReceive('cookieJar')
            ->twice()
            ->withNoArgs()
            ->andReturn($cookieJar);

        $request = Mockery::mock(Psr\Http\Message\RequestInterface::class);
        $request->shouldReceive('getCookieParams')
                ->andReturn([]);

        $this->http->shouldReceive('request')
            ->withNoArgs()
            ->andReturn($request);

        $this->assertFalse($ilWACSignedPath->isSignedPath());
        $this->assertFalse($ilWACSignedPath->isSignedPathValid());
        $this->assertFalse($ilWACSignedPath->isFolderSigned());
        $this->assertFalse($ilWACSignedPath->isFolderTokenValid());
    }


    public function testSomeBasics()
    {
        $query = 'myparam=1234';
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->file_four->url() . '?'
                                                             . $query, false), $this->http, $this->cookieFactory);

        $this->assertEquals('dummy.jpg', $ilWACSignedPath->getPathObject()->getFileName());
        $this->assertEquals($query, $ilWACSignedPath->getPathObject()->getQuery());
        $this->assertEquals('./data/' . self::CLIENT_NAME
                            . '/sec/ilBlog/mm_124/', $ilWACSignedPath->getPathObject()
                                                                     ->getSecurePath());
        $this->assertEquals('ilBlog', $ilWACSignedPath->getPathObject()->getSecurePathId());
        $this->assertFalse($ilWACSignedPath->getPathObject()->isStreamable());
    }


    public function testTokenGeneration()
    {
        $ilWacPath = new ilWacPath($this->file_four->url(), false);
        $ilWACToken = new ilWACToken($ilWacPath->getPath(), self::CLIENT_NAME, 123456, 20);
        $ilWACToken->generateToken();
        $this->assertEquals('SALT-client_name-123456-20', $ilWACToken->getRawToken());
        $this->assertEquals('./data/client_name/sec/ilBlog/mm_124/dummy.jpg', $ilWACToken->getId());

        $this->assertEquals(self::SALT, ilWACToken::getSALT());
        $ilWACToken = new ilWACToken($ilWacPath->getPath(), self::CLIENT_NAME, 123456, 20);
        $this->assertEquals('b541e2bae42ee222f9be959b7ad2ab8844cbb05b', $ilWACToken->getToken());
        $this->assertEquals('e45b98f267dc891c8206c844f7df29ea', $ilWACToken->getHashedId());
    }


    public function testCookieGeneration()
    {
        $this->markTestSkipped('unable to use http cookies at this point');
        $expected_cookies = [
            '19ab58dae37d8d8cf931727c35514642',
            '19ab58dae37d8d8cf931727c35514642ts',
            '19ab58dae37d8d8cf931727c35514642ttl',
        ];

        $cookieJar = Mockery::mock(CookieJar::class);

        $response = Mockery::mock(ResponseInterface::class);

        $this->http
            ->shouldReceive('response')
            ->times(3)
            ->withNoArgs()
            ->andReturn($response)
            ->getMock();

        $cookieJar
            ->shouldReceive('with')
            ->times(3)
            ->with(new CookieWrapper(SetCookie::create('')))
            ->andReturnSelf()
            ->getMock()

            ->shouldReceive('with')
            ->times(3)
            ->with(new CookieWrapper(SetCookie::create('')))
            ->andReturnSelf()
            ->getMock()

            ->shouldReceive('with')
            ->times(3)
            ->with(new CookieWrapper(SetCookie::create('')))
            ->andReturnSelf()
            ->getMock();

        $this->http->shouldReceive('cookieJar')
            ->withNoArgs()
            ->andReturn($cookieJar);

        ilWACSignedPath::signFolderOfStartFile($this->file_one->url());

        // in subfolder
        ilWACSignedPath::signFolderOfStartFile($this->file_one_subfolder->url());

        // in sub-subfolder
        ilWACSignedPath::signFolderOfStartFile($this->file_one_subfolder->url());
    }


    public function testFileToken()
    {
        ilWACSignedPath::setTokenMaxLifetimeInSeconds(self::LIFETIME);
        $lifetime = ilWACSignedPath::getTokenMaxLifetimeInSeconds();

        // Request within lifetime
        $signed_path = ilWACSignedPath::signFile($this->file_one->url());
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($signed_path, false), $this->http, $this->cookieFactory);

        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertTrue($ilWACSignedPath->isSignedPathValid());
        $this->assertEquals($ilWACSignedPath->getPathObject()->getClient(), self::CLIENT_NAME);
        $this->assertFalse($ilWACSignedPath->getPathObject()->isInSecFolder());
        $this->assertTrue($ilWACSignedPath->getPathObject()->isImage());
        $this->assertFalse($ilWACSignedPath->getPathObject()->isAudio());
        $this->assertFalse($ilWACSignedPath->getPathObject()->isVideo());
        $this->assertTrue($ilWACSignedPath->getPathObject()->hasTimestamp());
        $this->assertTrue($ilWACSignedPath->getPathObject()->hasToken());

        // Request after lifetime
        $signed_path = ilWACSignedPath::signFile($this->file_four->url());
        sleep($lifetime + self::ADDITIONAL_TIME);
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($signed_path, false), $this->http, $this->cookieFactory);
        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertFalse($ilWACSignedPath->isSignedPathValid());
    }



    /**
     * @Test
     */
    public function testModifiedTimestampNoMod()
    {
        // self::markTestSkipped("WIP");
        // return;
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->getModifiedSignedPath(0, 0), false), $this->http, $this->cookieFactory);
        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertTrue($ilWACSignedPath->isSignedPathValid());
    }


    /**
     * @Test
     */
    public function testModifiedTimestampAddTime()
    {
        // self::markTestSkipped("WIP");
        // return;
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->getModifiedSignedPath(self::ADDITIONAL_TIME, 0), false), $this->http, $this->cookieFactory);
        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertFalse($ilWACSignedPath->isSignedPathValid());
    }


    public function testModifiedTimestampSubTime()
    {
        // self::markTestSkipped("WIP");
        // return;
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->getModifiedSignedPath(self::ADDITIONAL_TIME
                                                                                          * -1, 0), false), $this->http, $this->cookieFactory);
        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertFalse($ilWACSignedPath->isSignedPathValid());
    }


    public function testModifiedTTL()
    {
        // self::markTestSkipped("WIP");
        // return;
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->getModifiedSignedPath(0, 1), false), $this->http, $this->cookieFactory);
        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertFalse($ilWACSignedPath->isSignedPathValid());
    }


    public function testModifiedTTLAndTimestamp()
    {
        // self::markTestSkipped("WIP");
        // return;
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->getModifiedSignedPath(1, 1), false), $this->http, $this->cookieFactory);
        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertFalse($ilWACSignedPath->isSignedPathValid());
    }


    public function testModifiedToken()
    {
        // self::markTestSkipped("WIP");
        // return;
        $ilWACSignedPath = new ilWACSignedPath(new ilWACPath($this->getModifiedSignedPath(0, 0, md5('LOREM')), false), $this->http, $this->cookieFactory);
        $this->assertTrue($ilWACSignedPath->isSignedPath());
        $this->assertFalse($ilWACSignedPath->isSignedPathValid());
    }


    /**
     * @param int $add_ttl
     * @param int $add_timestamp
     * @param null $override_token
     * @return string
     */
    protected function getModifiedSignedPath($add_ttl = 0, $add_timestamp = 0, $override_token = null)
    {
        ilWACSignedPath::setTokenMaxLifetimeInSeconds(self::LIFETIME);
        $signed_path = ilWACSignedPath::signFile($this->file_one->url());

        $parts = parse_url($signed_path);
        $path = $parts['path'];
        $query = $parts['query'];
        parse_str($query, $query_array);
        $token = $override_token ? $override_token : $query_array['il_wac_token'];
        $ttl = (int) $query_array['il_wac_ttl'];
        $ts = (int) $query_array['il_wac_ts'];
        $path_with_token = $path . '?il_wac_token=' . $token;

        $modified_ttl = $ttl + $add_ttl;
        $modified_ts = $ts + $add_timestamp;

        return $path_with_token . '&il_wac_ttl=' . $modified_ttl . '&il_wac_ts=' . $modified_ts;
    }
}
