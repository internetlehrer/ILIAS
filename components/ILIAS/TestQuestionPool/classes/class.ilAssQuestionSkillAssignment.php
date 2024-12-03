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

declare(strict_types=1);

use ILIAS\Skill\Service\SkillTreeService;

/**
 * @author		Björn Heyser <bheyser@databay.de>
 * @version		$Id$
 *
 * @package components\ILIAS/Test
 */
class ilAssQuestionSkillAssignment
{
    public const DEFAULT_COMPETENCE_POINTS = 1;

    public const EVAL_MODE_BY_QUESTION_RESULT = 'result';
    public const EVAL_MODE_BY_QUESTION_SOLUTION = 'solution';

    private ilDBInterface $db;
    private int $parentObjId;
    private int $questionId;
    private int $skillBaseId;
    private int $skillTrefId;
    private int $skillPoints;
    private string $skillTitle = '';
    private string $skillPath = '';
    private string $evalMode;

    private ilAssQuestionSolutionComparisonExpressionList $solutionComparisonExpressionList;
    private SkillTreeService $skill_tree_service;

    public function __construct(ilDBInterface $db)
    {
        global $DIC;

        $this->db = $db;

        $this->solutionComparisonExpressionList = new ilAssQuestionSolutionComparisonExpressionList($this->db);
        $this->skill_tree_service = $DIC->skills()->tree();
    }

    public function loadFromDb(): void
    {
        $query = "
			SELECT obj_fi, question_fi, skill_base_fi, skill_tref_fi, skill_points, eval_mode
			FROM qpl_qst_skl_assigns
			WHERE obj_fi = %s
			AND question_fi = %s
			AND skill_base_fi = %s
			AND skill_tref_fi = %s
		";

        $res = $this->db->queryF(
            $query,
            ['integer', 'integer', 'integer', 'integer'],
            [$this->parentObjId, $this->questionId, $this->skillBaseId, $this->skillTrefId]
        );

        $row = $this->db->fetchAssoc($res);

        if (is_array($row)) {
            $this->setSkillPoints($row['skill_points']);
            $this->setEvalMode($row['eval_mode']);
        }

        if ($this->getEvalMode() == self::EVAL_MODE_BY_QUESTION_SOLUTION) {
            $this->loadComparisonExpressions();
        }
    }

    public function loadComparisonExpressions(): void
    {
        $this->initSolutionComparisonExpressionList();
        $this->solutionComparisonExpressionList->load();
    }

    public function saveToDb(): void
    {
        if ($this->dbRecordExists()) {
            $this->db->update(
                'qpl_qst_skl_assigns',
                [
                    'skill_points' => ['integer', $this->getSkillPoints()],
                    'eval_mode' => ['text', $this->getEvalMode()]
                ],
                [
                    'obj_fi' => ['integer', $this->getParentObjId()],
                    'question_fi' => ['integer', $this->getQuestionId()],
                    'skill_base_fi' => ['integer', $this->getSkillBaseId()],
                    'skill_tref_fi' => ['integer', $this->getSkillTrefId()]
                ]
            );
        } else {
            $this->db->insert('qpl_qst_skl_assigns', [
                'obj_fi' => ['integer', $this->getParentObjId()],
                'question_fi' => ['integer', $this->getQuestionId()],
                'skill_base_fi' => ['integer', $this->getSkillBaseId()],
                'skill_tref_fi' => ['integer', $this->getSkillTrefId()],
                'skill_points' => ['integer', $this->getSkillPoints()],
                'eval_mode' => ['text', $this->getEvalMode()]
            ]);
        }

        if ($this->getEvalMode() == self::EVAL_MODE_BY_QUESTION_SOLUTION) {
            $this->saveComparisonExpressions();
        }
    }

    public function saveComparisonExpressions(): void
    {
        $this->initSolutionComparisonExpressionList();
        $this->solutionComparisonExpressionList->save();
    }

    public function deleteFromDb(): void
    {
        $query = "
			DELETE FROM qpl_qst_skl_assigns
			WHERE obj_fi = %s
			AND question_fi = %s
			AND skill_base_fi = %s
			AND skill_tref_fi = %s
		";

        $this->db->manipulateF(
            $query,
            ['integer', 'integer', 'integer', 'integer'],
            [$this->getParentObjId(), $this->getQuestionId(), $this->getSkillBaseId(), $this->getSkillTrefId()]
        );

        $this->deleteComparisonExpressions();
    }

    public function deleteComparisonExpressions(): void
    {
        $this->initSolutionComparisonExpressionList();
        $this->solutionComparisonExpressionList->delete();
    }

    public function dbRecordExists(): bool
    {
        $query = "
			SELECT COUNT(*) cnt
			FROM qpl_qst_skl_assigns
			WHERE obj_fi = %s
			AND question_fi = %s
			AND skill_base_fi = %s
			AND skill_tref_fi = %s
		";

        $res = $this->db->queryF(
            $query,
            ['integer', 'integer', 'integer', 'integer'],
            [$this->getParentObjId(), $this->getQuestionId(), $this->getSkillBaseId(), $this->getSkillTrefId()]
        );

        $row = $this->db->fetchAssoc($res);

        return $row['cnt'] > 0;
    }

    public function isSkillUsed(): bool
    {
        $query = "
			SELECT COUNT(*) cnt
			FROM qpl_qst_skl_assigns
			WHERE obj_fi = %s
			AND skill_base_fi = %s
			AND skill_tref_fi = %s
		";

        $res = $this->db->queryF(
            $query,
            ['integer', 'integer', 'integer'],
            [$this->getParentObjId(), $this->getSkillBaseId(), $this->getSkillTrefId()]
        );

        $row = $this->db->fetchAssoc($res);

        return $row['cnt'] > 0;
    }

    public function setSkillPoints(int $skillPoints): void
    {
        $this->skillPoints = $skillPoints;
    }

    public function getSkillPoints(): int
    {
        return $this->skillPoints;
    }

    public function setQuestionId(int $questionId): void
    {
        $this->questionId = $questionId;
    }

    public function getQuestionId(): int
    {
        return $this->questionId;
    }

    public function setSkillBaseId(int $skillBaseId): void
    {
        $this->skillBaseId = $skillBaseId;
    }

    public function getSkillBaseId(): int
    {
        return $this->skillBaseId;
    }

    public function setSkillTrefId(int $skillTrefId): void
    {
        $this->skillTrefId = $skillTrefId;
    }

    public function getSkillTrefId(): int
    {
        return $this->skillTrefId;
    }

    public function setParentObjId(int $parentObjId): void
    {
        $this->parentObjId = $parentObjId;
    }

    public function getParentObjId(): int
    {
        return $this->parentObjId;
    }

    public function loadAdditionalSkillData(): void
    {
        $this->setSkillTitle(
            ilBasicSkill::_lookupTitle($this->getSkillBaseId(), $this->getSkillTrefId())
        );

        $path = $this->skill_tree_service->getSkillTreePath(
            $this->getSkillBaseId(),
            $this->getSkillTrefId()
        );

        $nodes = [];
        foreach ($path as $node) {
            if ($node['title'] === "Skill Tree Root Node") {
                continue;
            }

            if ($node['child'] > 1 && $node['skill_id'] != $this->getSkillBaseId()) {
                $nodes[] = $node['title'];
            }
        }

        $root_node = reset($path);
        array_unshift(
            $nodes,
            $this->skill_tree_service->getObjSkillTreeById($root_node['skl_tree_id'])->getTitle()
        );

        $this->setSkillPath(implode(' > ', $nodes));
    }

    public function setSkillTitle($skillTitle): void
    {
        $this->skillTitle = $skillTitle;
    }

    public function getSkillTitle(): ?string
    {
        return $this->skillTitle;
    }

    public function setSkillPath($skillPath): void
    {
        $this->skillPath = $skillPath;
    }

    public function getSkillPath(): ?string
    {
        return $this->skillPath;
    }

    public function getEvalMode(): string
    {
        return $this->evalMode;
    }

    public function setEvalMode(string $evalMode): void
    {
        $this->evalMode = $evalMode;
    }

    public function hasEvalModeBySolution(): bool
    {
        return $this->evalMode === self::EVAL_MODE_BY_QUESTION_SOLUTION;
    }

    public function initSolutionComparisonExpressionList(): void
    {
        $this->solutionComparisonExpressionList->setQuestionId($this->getQuestionId());
        $this->solutionComparisonExpressionList->setSkillBaseId($this->getSkillBaseId());
        $this->solutionComparisonExpressionList->setSkillTrefId($this->getSkillTrefId());
    }

    public function getSolutionComparisonExpressionList(): ilAssQuestionSolutionComparisonExpressionList
    {
        return $this->solutionComparisonExpressionList;
    }

    public function getMaxSkillPoints(): int
    {
        if ($this->hasEvalModeBySolution()) {
            $maxPoints = 0;

            foreach ($this->solutionComparisonExpressionList->get() as $expression) {
                if ($expression->getPoints() > $maxPoints) {
                    $maxPoints = $expression->getPoints();
                }
            }

            return $maxPoints;
        }

        return $this->getSkillPoints();
    }

    /**
     * @param mixed $skillPoints
     */
    public function isValidSkillPoint($skillPoints): bool
    {
        return (
            is_numeric($skillPoints) &&
            str_replace(['.', ','], '', $skillPoints) == $skillPoints &&
            $skillPoints > 0
        );
    }
}
