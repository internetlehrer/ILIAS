<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 */

namespace ILIAS\Style\Content\Setup;

use ILIAS\Setup;

/**
 * @author Alexander Killing <killing@leifos.de>
 */
class ContentStyleAgent extends Setup\Agent\NullAgent
{
    public function getUpdateObjective(Setup\Config $config = null): Setup\Objective
    {
        return new Setup\ObjectiveCollection(
            'Content Style Update',
            true,
            new \ilDatabaseUpdateStepsExecutedObjective(new ilStyleDBUpdateSteps()),
            new \ilDatabaseUpdateStepsExecutedObjective(new ilStyle9HotfixDBUpdateSteps())
        );
    }
}
