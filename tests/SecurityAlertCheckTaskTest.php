<?php

namespace BringYourOwnIdeas\SecurityChecker\Tests;

use BringYourOwnIdeas\SecurityChecker\Models\SecurityAlert;
use BringYourOwnIdeas\SecurityChecker\Tasks\SecurityAlertCheckTask;
use SensioLabs\Security\Result;
use SensioLabs\Security\SecurityChecker;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class SecurityAlertCheckTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var SecurityAlertCheckTask
     */
    private $checkTask;

    protected function setUp()
    {
        parent::setUp();

        QueuedJobService::config()->set('use_shutdown_function', false);

        $securityCheckerMock = $this->getSecurityCheckerMock();
        $checkTask = new SecurityAlertCheckTask;
        $checkTask->setSecurityChecker($securityCheckerMock);
        $this->checkTask = $checkTask;
    }

    /**
     * Run task buffering the output as so that it does not interfere with the test harness output.
     *
     * @param null|HTTPRequest $request
     *
     * @return string buffered output
     */
    private function runTask($request = null)
    {
        ob_start();
        $this->checkTask->run($request);
        return ob_get_clean();
    }

    /**
     * provide a mock to remove dependency on external service
     */
    protected function getSecurityCheckerMock($empty = false)
    {
        // Mock info comes from SensioLabs API docs example output,
        // and a real (test) silverstripe/installer 3.2.0 installation
        // (using the aforementioned API)
        $mockOutput = <<<CVENOTICE
{
    "symfony\/symfony": {
        "version": "2.1.x-dev",
        "advisories": {
            "symfony\/symfony\/CVE-2013-1397.yaml": {
                "title": "Ability to enable\/disable object support in YAML parsing and dumping",
                "link": "http:\/\/symfony.com\/blog\/security-release-symfony-2-0-22-and-2-1-7-released",
                "cve": "CVE-2013-1397"
            }
        }
    },
    "silverstripe\/framework": {
        "version": "3.2.0",
        "advisories": {
            "silverstripe\/framework\/SS-2016-002-1.yaml": {
                "title": "SS-2016-002: CSRF vulnerability in GridFieldAddExistingAutocompleter",
                "link": "https:\/\/www.silverstripe.org\/download\/security-releases\/ss-2016-002\/",
                "cve": ""
            },
            "silverstripe\/framework\/SS-2016-003-1.yaml": {
                "title": "SS-2016-003: Hostname, IP and Protocol Spoofing through HTTP Headers",
                "link": "https:\/\/www.silverstripe.org\/download\/security-releases\/ss-2016-003\/",
                "cve": ""
            },
            "silverstripe\/framework\/SS-2015-028-1.yaml": {
                "title": "SS-2015-028: Missing security check on dev\/build\/defaults",
                "link": "https:\/\/www.silverstripe.org\/download\/security-releases\/ss-2015-028\/",
                "cve": ""
            },
            "silverstripe\/framework\/SS-2015-027-1.yaml": {
                "title": "SS-2015-027: HtmlEditor embed url sanitisation",
                "link": "https:\/\/www.silverstripe.org\/download\/security-releases\/ss-2015-027\/",
                "cve": ""
            },
            "silverstripe\/framework\/SS-2015-026-1.yaml": {
                "title": "SS-2015-026: Form field validation message XSS vulnerability",
                "link": "https:\/\/www.silverstripe.org\/download\/security-releases\/ss-2015-026\/",
                "cve": ""
            }
        }
    }
}
CVENOTICE;

        $securityCheckerMock = $this->getMockBuilder(SecurityChecker::class)->setMethods(['check'])->getMock();
        $securityCheckerMock->expects($this->any())->method('check')->will($this->returnValue(
            $empty ? new Result(0, '{}', 'json') : new Result(6, $mockOutput, 'json')
        ));

        return $securityCheckerMock;
    }

    public function testUpdatesAreSaved()
    {
        $preCheck = SecurityAlert::get();
        $this->assertCount(0, $preCheck, 'database is empty to begin with');

        $this->runTask();

        $postCheck = SecurityAlert::get();
        $this->assertCount(6, $postCheck, 'SecurityAlert has been stored');
    }

    public function testNoDuplicates()
    {
        $this->runTask();

        $postCheck = SecurityAlert::get();
        $this->assertCount(6, $postCheck, 'SecurityAlert has been stored');

        $this->runTask();

        $postCheck = SecurityAlert::get();
        $this->assertCount(6, $postCheck, 'The SecurityAlert isn\'t stored twice.');
    }

    public function testSecurityAlertRemovals()
    {
        $this->runTask();

        $preCheck = SecurityAlert::get();
        $this->assertCount(6, $preCheck, 'database has stored SecurityAlerts');

        $securityCheckerMock = $this->getSecurityCheckerMock(true);
        $this->checkTask->setSecurityChecker($securityCheckerMock);

        $this->runTask();

        $postCheck = SecurityAlert::get();
        $this->assertCount(0, $postCheck, 'database is empty to finish with');
    }

    public function testIdentifierSetsFromTitleIfCVEIsNotSet()
    {
        $this->runTask();
        $frameworkAlert = SecurityAlert::get()
            ->filter('PackageName', 'silverstripe/framework')
            ->first();
        $this->assertNotEmpty($frameworkAlert->Identifier);
        $this->assertRegExp('/^SS-201[56]-\d{3}$/', $frameworkAlert->Identifier);
    }
}
