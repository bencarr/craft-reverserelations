<?php

namespace robuust\reverserelations\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table;
use craft\fields\Users;
use craft\helpers\Db;

/**
 * Reverse Relations Users Field.
 *
 * @author    Bob Olde Hampsink <bob@robuust.digital>
 * @copyright Copyright (c) 2019, Robuust
 * @license   MIT
 *
 * @see       https://robuust.digital
 */
class ReverseUsers extends Users
{
    use ReverseRelationsTrait;

    /**
     * {@inheritdoc}
     */
    public static function displayName(): string
    {
        return Craft::t('reverserelations', 'Reverse User Relations');
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {
        // Use the canonical element
        if ($element) {
            $element = $element->getCanonical();
        }

        /** @var Element|null $element */
        $query = parent::normalizeValue($value, $element);

        // Overwrite inner join to switch sourceId and targetId
        if (!is_array($value) && $value !== '' && $element && $element->id) {
            $targetField = Craft::$app->fields->getFieldByUid($this->targetFieldId);

            $query->join = [];
            $query->innerJoin('{{%relations}} relations', [
                'and',
                '[[relations.sourceId]] = [[elements.id]]',
                [
                    'relations.targetId' => $element->id,
                    'relations.fieldId' => $targetField->id,
                ],
                [
                    'or',
                    ['relations.sourceSiteId' => null],
                    ['relations.sourceSiteId' => $element->siteId],
                ],
            ]);

            $inputSourceIds = $this->inputSourceIds();
            if ($inputSourceIds != '*') {
                $query
                    ->innerJoin('{{%usergroups_users}} usergroups', '[[relations.sourceId]] = [[usergroups.userId]]')
                    ->where(['usergroups.groupId' => $inputSourceIds]);
            }
        }

        return $query;
    }

    /**
     * Get original relations so we can diff those
     * with the new value and find out which ones need to be deleted.
     *
     * {@inheritdoc}
     */
    public function beforeElementSave(ElementInterface $element, bool $isNew): bool
    {
        if (!$isNew || $element->getIsDerivative()) {
            // Get cached element
            $user = Craft::$app->getUsers()->getUserById($element->getCanonicalId());

            // Get old sources
            if ($user && $user->{$this->handle}) {
                $this->oldSources = $user->{$this->handle}->anyStatus()->all();
            }
        }

        return parent::beforeElementSave($element, $isNew);
    }

    /**
     * {@inheritdoc}
     */
    public function getEagerLoadingMap(array $sourceElements)
    {
        $targetField = Craft::$app->fields->getFieldByUid($this->targetFieldId);

        /** @var Element|null $firstElement */
        $firstElement = $sourceElements[0] ?? null;

        // Get the source element IDs
        $sourceElementIds = [];

        foreach ($sourceElements as $sourceElement) {
            $sourceElementIds[] = $sourceElement->id;
        }

        // Return any relation data on these elements, defined with this field
        $map = (new Query())
            ->select(['sourceId as target', 'targetId as source'])
            ->from('{{%relations}} relations')
            ->innerJoin('{{%users}} users', '[[relations.sourceId]] = [[users.id]]')
            ->innerJoin('{{%usergroups_users}} usergroups', '[[relations.sourceId]] = [[usergroups.userId]]')
            ->where([
                'and',
                [
                    'fieldId' => $targetField->id,
                    'targetId' => $sourceElementIds,
                ],
                [
                    'or',
                    ['sourceSiteId' => $firstElement ? $firstElement->siteId : null],
                    ['sourceSiteId' => null],
                ],
            ])
            ->where(['usergroups.groupId' => $this->inputSourceIds()])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        // Figure out which target site to use
        $targetSite = $this->targetSiteId($firstElement);

        return [
            'elementType' => static::elementType(),
            'map' => $map,
            'criteria' => [
                'siteId' => $targetSite,
            ],
        ];
    }

    /**
     * Get available fields.
     *
     * @return array
     */
    protected function getFields(): array
    {
        $fields = [];
        /** @var Field $field */
        foreach (Craft::$app->fields->getAllFields(false) as $field) {
            if ($field instanceof Users && !($field instanceof $this)) {
                $fields[$field->uid] = $field->name.' ('.$field->handle.')';
            }
        }

        return $fields;
    }

    /**
     * Get allowed input source ids.
     *
     * @return array|string
     */
    private function inputSourceIds()
    {
        $inputSources = $this->inputSources();

        if ($inputSources == '*') {
            return $inputSources;
        }

        $sources = [];
        foreach ($inputSources as $source) {
            list($type, $uid) = explode(':', $source);
            $sources[] = $uid;
        }

        return Db::idsByUids(Table::USERGROUPS, $sources);
    }
}
