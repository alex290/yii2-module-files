<?php
/**
 * Created by PhpStorm.
 * User: floor12
 * Date: 01.01.2018
 * Time: 12:56
 */

namespace floor12\files\logic;

use floor12\files\models\File;
use floor12\files\models\FileType;
use Yii;
use yii\base\ErrorException;
use yii\web\BadRequestHttpException;
use yii\web\IdentityInterface;
use yii\web\UploadedFile;

/**
 * Class FileCreateFromInstance
 * @package floor12\files\logic
 */
class FileCreateFromInstance
{
    private $_model;
    private $_owner;
    private $_attribute;
    private $_instance;
    private $_fullPath;
    private $_onlyUploaded;

    public function __construct(UploadedFile $file, array $data, IdentityInterface $identity = null, $onlyUploaded = true)
    {

        $this->_onlyUploaded = $onlyUploaded;

        if (!isset($data['attribute']) || !$data['attribute'] || !isset($data['modelClass']) || !$data['modelClass'])
            throw new BadRequestHttpException("Attribute or class name not set.");

        // Загружаем полученные данные
        $this->_instance = $file;
        $this->_attribute = $data['attribute'];

        if (!file_exists($this->_instance->tempName))
            throw new ErrorException("Tmp file not found on disk.");

        // Инициализируем класс владельца файла для валидаций и ставим сценарий
        $this->_owner = new $data['modelClass'];

        if (isset($data['scenario']))
            $this->_owner->setScenario($data['scenario']);


        if (isset($this->_owner->behaviors['files']->attributes[$this->_attribute]['validator'])) {
            foreach ($this->_owner->behaviors['files']->attributes[$this->_attribute]['validator'] as $validator) {
                if (!$validator->validate($this->_instance, $error))
                    throw new BadRequestHttpException($error);
            }

        }

        // Создаем модель нового файла и заполняем первоначальными данными
        $this->_model = new File();
        $this->_model->created = time();
        $this->_model->field = $this->_attribute;
        $this->_model->class = $data['modelClass'];

        $this->_model->filename = new PathGenerator(Yii::$app->getModule('files')->storageFullPath) . '.' . $this->_instance->extension;
        $this->_model->title = $this->_instance->name;
        $this->_model->content_type = $this->_instance->type;
        $this->_model->size = $this->_instance->size;
        $this->_model->type = $this->detectType();
        if ($identity)
            $this->_model->user_id = $identity->id;
        if ($this->_model->type == FileType::VIDEO)
            $this->_model->video_status = 0;

        //Генерируем полный новый адрес сохранения файла
        $this->_fullPath = Yii::$app->getModule('files')->storageFullPath . DIRECTORY_SEPARATOR . $this->_model->filename;
    }

    /**
     * @return string
     */
    public function detectType()
    {
        $contentTypeArray = explode('/', $this->_model->content_type);
        if ($contentTypeArray[0] == 'image')
            return FileType::IMAGE;
        if ($contentTypeArray[0] == 'video')
            return FileType::VIDEO;
        return FileType::FILE;
    }

    /**
     * @return File
     */

    public function execute()
    {
        $path = Yii::$app->getModule('files')->storageFullPath . $this->_model->filename;

        if ($this->_model->save()) {
            if (!$this->_onlyUploaded)
                copy($this->_instance->tempName, $this->_fullPath);
            else
                $this->_instance->saveAs($this->_fullPath, false);
        }

        if ($this->_model->type == FileType::IMAGE) {
            $this->resizeAfterUpload();
        }

        return $this->_model;
    }


    protected function resizeAfterUpload()
    {
        $maxWidth = $this->_owner->behaviors['files']->attributes[$this->_attribute]['maxWidth'] ?? 0;
        $maxHeight = $this->_owner->behaviors['files']->attributes[$this->_attribute]['maxHeight'] ?? 0;

        if ($maxWidth && $maxHeight) {
            $resizer = new FileResize($this->_model, $maxWidth, $maxHeight);
            $resizer->execute();
        }

    }
}