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

/**
 * Class ilTestParticipantList
 *
 * @author    Björn Heyser <info@bjoernheyser.de>
 * @version    $Id$
 *
 * @package    Modules/Test
 * @implements Iterator<ilTestParticipant>
 */
class ilTestParticipantList implements Iterator
{
    /**
     * @var ilTestParticipant[]
     */
    protected array $participants = [];

    /**
     * @var ilObjTest
     */
    protected $testObj;

    /**
     * @param ilObjTest $testObj
     */
    public function __construct(ilObjTest $testObj)
    {
        $this->testObj = $testObj;
    }

    /**
     * @return ilObjTest
     */
    public function getTestObj(): ilObjTest
    {
        return $this->testObj;
    }

    /**
     * @param ilObjTest $testObj
     */
    public function setTestObj($testObj)
    {
        $this->testObj = $testObj;
    }

    /**
     * @param ilTestParticipant $participant
     */
    public function addParticipant(ilTestParticipant $participant)
    {
        $this->participants[] = $participant;
    }

    public function getParticipantByUsrId($usrId)
    {
        foreach ($this as $participant) {
            if ($participant->getUsrId() != $usrId) {
                continue;
            }

            return $participant;
        }
        return null;
    }

    public function getParticipantByActiveId($activeId): ?ilTestParticipant
    {
        foreach ($this as $participant) {
            if ($participant->getActiveId() != $activeId) {
                continue;
            }

            return $participant;
        }
        return null;
    }

    /**
     * @return bool
     */
    public function hasUnfinishedPasses(): bool
    {
        foreach ($this as $participant) {
            if ($participant->hasUnfinishedPasses()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function hasScorings(): bool
    {
        foreach ($this as $participant) {
            if ($participant->getScoring() instanceof ilTestParticipantScoring) {
                return true;
            }
        }

        return false;
    }

    public function getAllUserIds(): array
    {
        $usrIds = array();

        foreach ($this as $participant) {
            $usrIds[] = $participant->getUsrId();
        }

        return $usrIds;
    }

    public function getAllActiveIds(): array
    {
        $activeIds = array();

        foreach ($this as $participant) {
            $activeIds[] = $participant->getActiveId();
        }

        return $activeIds;
    }

    public function isActiveIdInList($activeId): bool
    {
        foreach ($this as $participant) {
            if ($participant->getActiveId() == $activeId) {
                return true;
            }
        }

        return false;
    }

    public function getAccessFilteredList(callable $userAccessFilter): ilTestParticipantList
    {
        $usrIds = call_user_func_array($userAccessFilter, [$this->getAllUserIds()]);

        $accessFilteredList = new self($this->getTestObj());

        foreach ($this as $participant) {
            if (in_array($participant->getUsrId(), $usrIds)) {
                $participant = clone $participant;
                $accessFilteredList->addParticipant($participant);
            }
        }

        return $accessFilteredList;
    }

    public function current(): ilTestParticipant
    {
        return current($this->participants);
    }
    public function next(): void
    {
        next($this->participants);
    }
    public function key(): int
    {
        return key($this->participants);
    }
    public function valid(): bool
    {
        return key($this->participants) !== null;
    }
    public function rewind(): void
    {
        reset($this->participants);
    }

    /**
     * @param array[] $dbRows
     */
    public function initializeFromDbRows($dbRows)
    {
        foreach ($dbRows as $rowKey => $rowData) {
            $participant = new ilTestParticipant();

            if ((int) $rowData['active_id']) {
                $participant->setActiveId((int) $rowData['active_id']);
            }

            $participant->setUsrId((int) $rowData['usr_id']);

            $participant->setLogin($rowData['login']);
            $participant->setLastname($rowData['lastname']);
            $participant->setFirstname($rowData['firstname']);
            $participant->setMatriculation($rowData['matriculation']);

            $participant->setActiveStatus((bool) ($rowData['active'] ?? false));

            if (isset($rowData['clientip'])) {
                $participant->setClientIp($rowData['clientip']);
            }

            $participant->setFinishedTries((int) $rowData['tries']);
            $participant->setTestFinished((bool) $rowData['test_finished']);
            $participant->setUnfinishedPasses((bool) $rowData['unfinished_passes']);

            $this->addParticipant($participant);
        }
    }

    /**
     * @return ilTestParticipantList
     */
    public function getScoredParticipantList(): ilTestParticipantList
    {
        require_once 'Modules/Test/classes/class.ilTestParticipantScoring.php';

        $scoredParticipantList = new self($this->getTestObj());

        global $DIC; /* @var ILIAS\DI\Container $DIC */

        $res = $DIC->database()->query($this->buildScoringsQuery());

        while ($row = $DIC->database()->fetchAssoc($res)) {
            $scoring = new ilTestParticipantScoring();

            $scoring->setActiveId((int) $row['active_fi']);
            $scoring->setScoredPass((int) $row['pass']);

            $scoring->setAnsweredQuestions((int) $row['answeredquestions']);
            $scoring->setTotalQuestions((int) $row['questioncount']);

            $scoring->setReachedPoints((float) $row['reached_points']);
            $scoring->setMaxPoints((float) $row['max_points']);

            $scoring->setPassed((bool) $row['passed']);
            $scoring->setFinalMark((string) $row['mark_short']);

            $this->getParticipantByActiveId($row['active_fi'])->setScoring($scoring);

            $scoredParticipantList->addParticipant(
                $this->getParticipantByActiveId($row['active_fi'])
            );
        }

        return $scoredParticipantList;
    }

    public function buildScoringsQuery(): string
    {
        global $DIC; /* @var ILIAS\DI\Container $DIC */

        $IN_activeIds = $DIC->database()->in(
            'tres.active_fi',
            $this->getAllActiveIds(),
            false,
            'integer'
        );

        if (false && !$this->getTestObj()->isDynamicTest()) { // BH: keep for the moment
            $closedScoringsOnly = "
				INNER JOIN tst_active tact
				ON tact.active_id = tres.active_fi
				AND tact.last_finished_pass = tact.last_started_pass
			";
        } else {
            $closedScoringsOnly = '';
        }

        $query = "
			SELECT * FROM tst_result_cache tres
			
			INNER JOIN tst_pass_result pres
			ON pres.active_fi = tres.active_fi
			AND pres.pass = tres.pass

			$closedScoringsOnly
			
			WHERE $IN_activeIds
		";

        return $query;
    }

    public function getParticipantsTableRows(): array
    {
        $rows = array();

        foreach ($this as $participant) {
            $row = array(
                'usr_id' => $participant->getUsrId(),
                'active_id' => $participant->getActiveId(),
                'login' => $participant->getLogin(),
                'clientip' => $participant->getClientIp(),
                'firstname' => $participant->getFirstname(),
                'lastname' => $participant->getLastname(),
                'name' => $this->buildFullname($participant),
                'started' => ($participant->getActiveId() > 0) ? 1 : 0,
                'unfinished' => $participant->hasUnfinishedPasses() ? 1 : 0,
                'finished' => $participant->isTestFinished() ? 1 : 0,
                'access' => $this->lookupLastAccess($participant->getActiveId()),
                'tries' => $this->lookupNrOfTries($participant->getActiveId())
            );

            $rows[] = $row;
        }

        return $rows;
    }

    public function getScoringsTableRows(): array
    {
        $rows = array();

        foreach ($this as $participant) {
            if (!$participant->hasScoring()) {
                continue;
            }

            $row = array(
                'usr_id' => $participant->getUsrId(),
                'active_id' => $participant->getActiveId(),
                'login' => $participant->getLogin(),
                'firstname' => $participant->getFirstname(),
                'lastname' => $participant->getLastname(),
                'name' => $this->buildFullname($participant)
            );

            if ($participant->getScoring()) {
                $row['scored_pass'] = $participant->getScoring()->getScoredPass();
                $row['answered_questions'] = $participant->getScoring()->getAnsweredQuestions();
                $row['total_questions'] = $participant->getScoring()->getTotalQuestions();
                $row['reached_points'] = $participant->getScoring()->getReachedPoints();
                $row['max_points'] = $participant->getScoring()->getMaxPoints();
                $row['percent_result'] = $participant->getScoring()->getPercentResult();
                $row['passed_status'] = $participant->getScoring()->isPassed();
                $row['final_mark'] = $participant->getScoring()->getFinalMark();
                $row['last_scored_access'] = ilObjTest::lookupLastTestPassAccess(
                    $participant->getActiveId(),
                    $participant->getScoring()->getScoredPass()
                );
                $row['finished_passes'] = $participant->getFinishedTries();
                $row['has_unfinished_passes'] = $participant->hasUnfinishedPasses();
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param integer $activeId
     * @return int|null
     */
    public function lookupNrOfTries($activeId): ?int
    {
        $maxPassIndex = ilObjTest::_getMaxPass($activeId);

        if ($maxPassIndex !== null) {
            $nrOfTries = $maxPassIndex + 1;
            return $nrOfTries;
        }

        return null;
    }

    /**
     * @param integer $activeId
     * @return string
     */
    protected function lookupLastAccess($activeId): string
    {
        if (!$activeId) {
            return '';
        }

        return $this->getTestObj()->_getLastAccess($activeId);
    }

    /**
     * @param ilTestParticipant $participant
     * @return string
     */
    protected function buildFullname(ilTestParticipant $participant): string
    {
        if ($this->getTestObj()->getFixedParticipants() && !$participant->getActiveId()) {
            return $this->buildInviteeFullname($participant);
        }

        return $this->buildParticipantsFullname($participant);
    }

    /**
     * @param ilTestParticipant $participant
     * @return string
     */
    protected function buildInviteeFullname(ilTestParticipant $participant): string
    {
        global $DIC; /* @var ILIAS\DI\Container $DIC */

        if (strlen($participant->getFirstname() . $participant->getLastname()) == 0) {
            return $DIC->language()->txt("deleted_user");
        }

        if ($this->getTestObj()->getAnonymity()) {
            return $DIC->language()->txt('anonymous');
        }

        return trim($participant->getLastname() . ", " . $participant->getFirstname());
    }

    /**
     * @param ilTestParticipant $participant
     * @return string
     */
    protected function buildParticipantsFullname(ilTestParticipant $participant): string
    {
        require_once 'Modules/Test/classes/class.ilObjTestAccess.php';
        return ilObjTestAccess::_getParticipantData($participant->getActiveId());
    }
}
