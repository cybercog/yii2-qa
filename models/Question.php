<?php

namespace artkost\qa\models;

use artkost\qa\ActiveRecord;
use artkost\qa\Module;
use Yii;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\helpers\Inflector;

/**
 * Question Model
 * @package artkost\qa\models
 *
 * @property integer $id
 * @property integer $user_id
 * @property string $title
 * @property string $alias
 * @property string $content
 * @property string $tags
 * @property integer $answers
 * @property integer $views
 * @property integer $votes
 * @property integer $status
 * @property integer $created_at
 * @property integer $updated_at
 *
 * @author Nikolay Kostyurin <nikolay@artkost.ru>
 * @since 2.0
 */
class Question extends ActiveRecord
{
    const STATUS_DRAFT = 0;
    const STATUS_PUBLISHED = 1;

    /**
     * Old tags populated after find record
     * @var string
     */
    protected $_oldTags = '';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%qa_question}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::className(),
            [
                'class' => AttributeBehavior::className(),
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => 'alias'
                ],
                'value' => function ($event) {
                    return Inflector::slug($event->sender->title);
                }
            ],
            [
                'class' => AttributeBehavior::className(),
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => 'status'
                ],
                'value' => function ($event) {
                    return self::STATUS_PUBLISHED;
                }
            ],
            [
                'class' => BlameableBehavior::className(),
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => 'user_id',
                ],
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['title', 'content', 'tags'], 'required'],
            [['tags'], 'normalizeTags']
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Module::t('model', 'ID'),
            'title' => Module::t('model', 'Title'),
            'alias' => Module::t('model', 'Alias'),
            'content' => Module::t('model', 'Content'),
            'tags' => Module::t('model', 'Tags'),
            'status' => Module::t('model', 'Status'),
        ];
    }

    /**
     * This is invoked when a record is populated with data from a find() call.
     */
    public function afterFind()
    {
        parent::afterFind();
        $this->_oldTags = $this->tags;
    }

    /**
     * This is invoked after the record is saved.
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        Tag::updateFrequency($this->_oldTags, $this->tags);
    }

    /**
     * This is invoked after the record is deleted.
     */
    public function afterDelete()
    {
        parent::afterDelete();
        Tag::updateFrequency($this->tags, '');
        Vote::removeRelation($this);
        Answer::removeRelation($this->id);
    }

    /**
     * @return array a list of links that point to the post list filtered by every tag of this post
     */
    public function getTagsList()
    {
        return Tag::string2Array($this->tags);
    }

    /**
     * @return string
     */
    public function getUpdated()
    {
        return Yii::$app->formatter->asTime($this->updated_at);
    }

    /**
     * @return string
     */
    public function getCreated()
    {
        return Yii::$app->formatter->asTime($this->created_at);
    }

    /**
     * @return int|string
     * @throws \yii\base\InvalidConfigException
     */
    public function getUserName()
    {
        return $this->user ? Module::getInstance()->getUserName($this->user) : $this->user_id;
    }

    /**
     * Check if current user can edit this model
     * @return bool
     */
    public function isAuthor()
    {
        return $this->user_id == Yii::$app->user->id;
    }

    /**
     * @param bool $user
     * @return bool
     */
    public function isFavorite($user = false)
    {
        $user = ($user) ? $user : Yii::$app->user;

        return Favorite::find()->where(['user_id' => $user->id, 'question_id' => $this->id])->exists();
    }

    /**
     * @param $data
     * @return bool
     */
    public function haveDraft($data)
    {
        return isset($data['draft']);
    }

    /**
     * @return bool
     */
    public function isDraft()
    {
        return $this->status == self::STATUS_DRAFT;
    }

    /**
     * @return bool
     */
    public function toggleFavorite()
    {
        if ($this->isFavorite()) {
            return Favorite::remove($this->id);
        } else {
            return Favorite::add($this->id);
        }
    }

    /**
     * Answer Relation
     * @return \yii\db\ActiveQueryInterface
     */
    public function getAnswers()
    {
        return $this->hasMany(Answer::className(), ['question_id' => 'id']);
    }

    /**
     * User Relation
     * @return \yii\db\ActiveQueryInterface
     */
    public function getUser()
    {
        return $this->hasOne(Yii::$app->user->identityClass, ['id' => 'user_id']);
    }

    /**
     * Favorite Relation
     * @return \yii\db\ActiveQueryInterface
     */
    public function getFavorite()
    {
        return $this->hasOne(Favorite::className(), ['question_id' => 'id']);
    }

    /**
     * Favorite Relation
     * @return \yii\db\ActiveQueryInterface
     */
    public function getFavorites()
    {
        return $this->hasMany(Favorite::className(), ['question_id' => 'id']);
    }

    /**
     * Normalizes the user-entered tags.
     */
    public function normalizeTags($attribute, $params)
    {
        $this->tags = Tag::array2String(array_unique(Tag::string2Array($this->tags)));
    }

    /**
     * Check if is given user unique
     * @return bool
     */
    public function isUserUnique()
    {
        return $this->user_id !== Yii::$app->user->id;
    }

    /**
     * @param $id
     */
    public static function incrementAnswers($id)
    {
        self::updateAllCounters(['answers' => 1], ['id' => $id]);
    }

    /**
     * @param $id
     */
    public static function decrementAnswers($id)
    {
        self::updateAllCounters(['answers' => -1], ['id' => $id]);
    }
}