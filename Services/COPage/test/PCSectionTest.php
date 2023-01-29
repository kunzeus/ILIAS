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

use PHPUnit\Framework\TestCase;

/**
 * @author Alexander Killing <killing@leifos.de>
 */
class PCSectionTest extends COPageTestBase
{
    public function testConstruction(): void
    {
        $page = $this->getEmptyPageWithDom();
        $pc_sec = new ilPCSection($page);
        $this->assertEquals(
            ilPCSection::class,
            get_class($pc_sec)
        );
    }

    public function testCreate(): void
    {
        $page = $this->getEmptyPageWithDom();
        $pc_sec = new ilPCSection($page);
        $pc_sec->create($page, "pg");
        $this->assertXmlEquals(
            '<PageObject HierId="pg"><PageContent><Section Characteristic="Block"></Section></PageContent></PageObject>',
            $page->getXMLFromDom()
        );
    }

    public function testProtected(): void
    {
        $page = $this->getEmptyPageWithDom();
        $pc_sec = new ilPCSection($page);
        $pc_sec->create($page, "pg");
        $pc_sec->setProtected(true);

        $this->assertEquals(
            true,
            $pc_sec->getProtected()
        );

        $expected = <<<EOT
<PageObject HierId="pg"><PageContent><Section Characteristic="Block" Protected="1"></Section></PageContent></PageObject>
EOT;

        $this->assertXmlEquals(
            $expected,
            $page->getXMLFromDom()
        );
    }

    public function testActiveFrom(): void
    {
        $page = $this->getEmptyPageWithDom();
        $pc_sec = new ilPCSection($page);
        $pc_sec->create($page, "pg");
        $pc_sec->setActiveFrom(1234);

        $this->assertEquals(
            1234,
            $pc_sec->getActiveFrom()
        );

        $expected = <<<EOT
<PageObject HierId="pg"><PageContent><Section Characteristic="Block" ActiveFrom="1234"></Section></PageContent></PageObject>
EOT;

        $this->assertXmlEquals(
            $expected,
            $page->getXMLFromDom()
        );
    }

    public function testActiveTo(): void
    {
        $page = $this->getEmptyPageWithDom();
        $pc_sec = new ilPCSection($page);
        $pc_sec->create($page, "pg");
        $pc_sec->setActiveTo(5678);

        $this->assertEquals(
            5678,
            $pc_sec->getActiveTo()
        );

        $expected = <<<EOT
<PageObject HierId="pg"><PageContent><Section Characteristic="Block" ActiveTo="5678"></Section></PageContent></PageObject>
EOT;

        $this->assertXmlEquals(
            $expected,
            $page->getXMLFromDom()
        );
    }
}
