<?php

namespace Headoo\ElasticSearchBundle\Tests\Command;

use Headoo\ElasticSearchBundle\Command\AbstractCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AbstractCommandTest extends KernelTestCase
{
    public function testCompleteLongLine()
    {
        $sMsg = str_repeat('*', AbstractCommand::LINE_LENGTH);

        self::assertEquals(
            $sMsg,
            AbstractCommand::completeLine($sMsg),
            'Message should not be modified'
        );
    }

    public function testCompleteLine()
    {
        $sMsg = '';

        self::assertEquals(
            AbstractCommand::LINE_LENGTH,
            strlen(AbstractCommand::completeLine($sMsg)),
            'Completed line should be equals to LINE_LENGTH'
        );
    }

}
