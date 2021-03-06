<?php

class CommentsController extends ControllerController
{
    /** @var bool показывать ли интерфейс администратора */
    protected $ifAdmin = false;

    public function __construct($ifAdmin = false)
    {
        if (CommonHelper::ifAjax() &&
            !empty($_REQUEST['checkAdmin']) &&
            UserComponent::getInstance()->userHasRole('admin')
        )
        {
            $this->ifAdmin = true;
        }
        else
        {
            $this->ifAdmin = $ifAdmin;
        }
    }

    /**
     * Валидация данных о комментарии.
     *
     * @param array $data подготовленная информация о комментарии (к примеру $_POST запроса)
     * @return array пустой массив в случае успеха или содержит элемент ['errors'] с массивами ошибок
     */
    protected function validateCommentIncomingData($data)
    {
        $res = [];

        $validationData = $data;

        if (!empty($_FILES['image']))
        {
            $maxFileSize = ConfigHelper::getInstance()->getConfig(
            )['site']['comments']['creation_settings']['max_file_size'];
            if ($_FILES['image']['error'] == UPLOAD_ERR_INI_SIZE
                || $_FILES['image']['error'] == UPLOAD_ERR_FORM_SIZE
                || $_FILES['image']['size'] > $maxFileSize
            )
            {
                $errMsg = 'Вы пытались загрузить слишком большой файл. Максимальный размер - '
                    . "$maxFileSize байт.";
                $errMsg .= $_FILES['image']['size'] ? " {$_FILES['image']['size']} байт - размер вашего файла." : '';
                $res['errors']['input_data'][] = [
                    'field' => 'image',
                    'message' => $errMsg
                ];
            }
            else
            {
                if ($_FILES['image']['name'])
                {
                    if ($_FILES['image']['error'])
                    {
                        $res['errors']['input_data'][] = [
                            'field' => 'image',
                            'message' => "Произошла ошибка с кодом {$_FILES['image']['error']}."
                        ];
                    }
                    else
                    {
                        if ($_FILES['image']['tmp_name'])
                        {
                            $validationData['image'] = $_FILES['image']['tmp_name'];
                        }
                        else
                        {
                            $res['errors']['input_data'][] = [
                                'field' => 'image',
                                'message' => 'Файл не загрузился по неизвестным причинам.'
                            ];
                        }
                    }
                }
            }
        }

        if ($validationResult = $this->commentDataValidation($validationData))
        {
            $res = array_merge_recursive($res, $validationResult);
        }

        return $res;
    }

    /*protected function mergeTableFieldsAndIncomingData($data)
    {
        $fields = [];
        $sqlFields = DbHelper::obj()->getFieldsName(CommentModel::getTableName());
        foreach ($sqlFields as $fld)
        {
            $fields[$fld->COLUMN_NAME] = '';
        }
        $fields = array_merge($fields, $data);

        return $fields;
    }*/

    public function actionGet()
    {
        $orderBy = (isset($_GET['order_by']) && in_array(
                $_GET['order_by'],
                CommentModel::getValidOrderFields(),
                true
            )) ? $_GET['order_by'] : 'created';

        $orderDir = (isset($_GET['order_direction']) && DbHelper::obj()->ifValidOrderDirection(
                $_GET['order_direction']
            )) ? $_GET['order_direction'] : 'DESC';

        $status = $this->ifAdmin ? false : 'APPROVED';
        $comments = CommentModel::getComments($orderBy, $orderDir, $status);

        foreach ($comments->rows as &$row)
        {
            $row['images_data'] = false;
            if ($row['image_name'])
            {
                $row['images_data'] = $this->fillImagesDataValue(
                    $row['image_name'],
                    ImageHelper::getThumbName($row['image_name'])
                );
            }
        }

        $html = $this->renderPartial(
            'comment_list',
            [
                'comments' => $comments->rows,
                'admin' => $this->ifAdmin
            ]
        );

        CommonHelper::sendHtmlResponse($html);
    }

    public function actionNew()
    {
        $fields = DbHelper::obj()->getFieldsName(CommentModel::getTableName());

        $fields = array_flip($fields);

        array_walk(
            $fields,
            function (&$item1)
            {
                $item1 = '';
            }
        );

        $fields = array_merge($fields, $_POST);

        $fields['username'] = trim($fields['username']);
        $fields['email'] = trim($fields['email']);

        if ($isNotValid = $this->validateCommentIncomingData($fields))
        {
            CommonHelper::sendJsonResponse(false, $isNotValid);
        }
        //$fields = $this->mergeTableFieldsAndIncomingData($data);

        $fields['images_data'] = [];

        if (!empty($_FILES['image']['tmp_name']))
        {
            if (is_uploaded_file($_FILES['image']['tmp_name']))
            {
                $temporary = empty($_GET['preview']) ? false : true;
                if (($images = ImageHelper::reduceImageToMaxDimensions($_FILES['image']['tmp_name'], true, $temporary))
                    && (!empty($images['new']) && !empty($images['new_thumb']))
                )
                {
                    $fields['images_data'] = $this->fillImagesDataValue(
                        $images['new']['name'],
                        $images['new_thumb']['name']
                    );
                    $fields['image_name'] = $images['new']['name'];
                }
                else
                {
                    LoggerComponent::getInstance()->log('Ошибка сохранения загруженного файла.');
                    CommonHelper::sendJsonResponse(
                        false,
                        ['errors' => ['common' => ['Ошибка сохранения загруженного файла']]]
                    );
                }
            }
            else
            {
                LoggerComponent::getInstance()->log('Ошибка загрузки файла.');
                CommonHelper::sendJsonResponse(false, ['errors' => ['common' => ['Ошибка загрузки файла']]]);
            }
        }

        if (empty($_GET['preview']))
        {
            if (!CommentModel::setNewComment($fields))
            {
                LoggerComponent::getInstance()->log('Не удалось сохранить комментарий.');
                CommonHelper::sendJsonResponse(false, ['errors' => ['common' => ['Не удалось сохранить комментарий']]]);
            }
        }
        else
        {
            $html = $this->renderPartial(
                '_comment',
                ['item' => $fields]
            );

            CommonHelper::sendHtmlResponse($html);
        }
    }

    protected function fillImagesDataValue($image, $imageThumb)
    {
        $imgPath = empty($_GET['preview']) ? '/images/comments/images/' : '/images/comments/images_temp/';

        $ret['image']['src'] = $imgPath . $image;
        $ret['image_thumb']['src'] = $imgPath . $imageThumb;

        $imageAbsPath = ConfigHelper::getInstance()->getConfig()['webDir'] . ltrim($ret['image']['src'], '/');
        $imageSize = getimagesize($imageAbsPath);
        $ret['image']['width'] = $imageSize[0];
        $ret['image']['height'] = $imageSize[1];

        $imageThumbAbsPath = ConfigHelper::getInstance()->getConfig()['webDir'] . ltrim(
                $ret['image_thumb']['src'],
                '/'
            );
        $imageThumbSize = getimagesize($imageThumbAbsPath);
        $ret['image_thumb']['width'] = $imageThumbSize[0];
        $ret['image_thumb']['height'] = $imageThumbSize[1];

        return $ret;
    }

    /**
     * Валидация полей комментариев.
     *
     * @param array $data список полей со значениями
     * @return array пустой массив в случае успеха или содержит элемент ['errors'] с массивами ошибок
     */
    protected function commentDataValidation($data)
    {
        $ret = [];

        $config = ConfigHelper::getInstance()->getConfig();

        $encoding = $config['globalEncoding'];
        $sizes = $config['site']['comments']['creation_settings']['text_sizes'];

        $lName = mb_strlen($data['username'], $encoding);
        $lEmail = mb_strlen($data['email'], $encoding);
        $lText = mb_strlen($data['text'], $encoding);

        if (($lName < $sizes['username']['min'] || $lName > $sizes['username']['max'])
            || ($lEmail < $sizes['email']['min'] || $lEmail > $sizes['email']['max'])
            || ($lText < $sizes['text']['min'] && $lText > $sizes['text']['max'])
            || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)
        )
        {
            // TODO: надо сделать вывод ошибки по каждому отдельному полю (в input_data), пока пусть будет общая ошибка
            // т. к. валидация должна проходить в js на стороне клиента (кроме изображений, которые на стороне клиента
            // проверить невозможно).
            $ret['errors']['common'][] = 'Ошибка валидации одного или нескольких полей';
        }

        if (!empty($data['image']) && ($imageResult = ImageHelper::validateCommentImage($data['image'])))
        {
            $ret['errors']['input_data'][] = [
                'field' => 'image',
                'message' => $imageResult['error']
            ];
        }

        return $ret;
    }

    public function actionIndex()
    {
        $this->title = CommonHelper::createTitle('список комментариев');
        $this->scripts['css'] .= CommonHelper::createCssLink('/css/comments.css');
        $this->scripts['js'] .= CommonHelper::createJsLink('/js/comments.js');

        $orderFields = CommentModel::getValidOrderFields();
        $orderLabels = CommentModel::getLabels();
        $orderTypes = [];
        foreach ($orderFields as $ot)
        {
            $orderTypes[] = [
                'id' => $ot,
                'name' => $orderLabels[$ot],
                'selected' => (empty($_GET['order_by']) ? false : ($_GET['order_by'] == $ot))
            ];
        }

        $directionSelect['asc'] = empty($_GET['order_direction']) ? false : ($_GET['order_direction'] == 'asc');
        $directionSelect['desc'] = empty($_GET['order_direction']) ? false : ($_GET['order_direction'] == 'desc');

        $fieldMaxLength = [];

        $confTS = ConfigHelper::getInstance()->getConfig()['site']['comments']['creation_settings']['text_sizes'];

        $fieldMaxLength['username'] = $confTS['username']['max'];
        $fieldMaxLength['email'] = $confTS['email']['max'];
        $fieldMaxLength['text'] = $confTS['text']['max'];

        $fieldMinLength['username'] = $confTS['username']['min'];
        $fieldMinLength['email'] = $confTS['email']['min'];
        $fieldMinLength['text'] = $confTS['text']['min'];

        $allowedRangeAlert['username'] = sprintf(
            _('Допустимое количество знаков в имени пользователя: [%d, %d]'),
            $fieldMinLength['username'],
            $fieldMaxLength['username']
        );
        $allowedRangeAlert['email'] = sprintf(
            _('Допустимое количество знаков в email: [%d, %d]'),
            $fieldMinLength['email'],
            $fieldMaxLength['email']
        );
        $allowedRangeAlert['text'] = sprintf(
            _('Допустимое количество знаков в сообщении: [%d, %d]'),
            $fieldMinLength['text'],
            $fieldMaxLength['text']
        );

        $wrongEmailAlert = _("Неправильный формат email");

        $imageParams = ConfigHelper::getInstance()->getConfig()['site']['comments']['creation_settings']['image'];

        $this->render(
            'index',
            [
                'checkAdmin' => $this->ifAdmin,
                'orderTypes' => $orderTypes,
                'directionSelect' => $directionSelect,
                'fieldMaxLength' => $fieldMaxLength,
                'fieldMinLength' => $fieldMinLength,
                'allowedRangeAlert' => $allowedRangeAlert,
                'wrongEmailAlert' => $wrongEmailAlert,
                'imageParams' => $imageParams,
                'maxFileSize' => ConfigHelper::getInstance()->getConfig(
                )['site']['comments']['creation_settings']['max_file_size']
            ]
        );
    }
}