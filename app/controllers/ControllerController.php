<?php

abstract class ControllerController
{
    /**
     * Default page title.
     *
     * @var string
     */
    protected $title = '';

    protected $layout = 'main.php';

    protected function renderPhpFile($file, $params = [])
    {
        ob_start();
        ob_implicit_flush(false);
        extract($params, EXTR_OVERWRITE);
        require($file);

        return ob_get_clean();
    }

    protected function render($view, $params = [])
    {
        $file = $this->getViewsPath() . "$view.php";

        $scripts = '';
        $content = $this->renderPhpFile($file, $params);
        require(APP_DIR . 'views/layouts/' . $this->layout);
    }

    protected function renderPartial($view, $params = [])
    {
        $file = $this->getViewsPath() . "$view.php";
        extract($params, EXTR_OVERWRITE);
        require($file);
    }

    protected function getViewsPath()
    {
        $className = get_class($this);
        $matchesCount = preg_match_all("/[A-Z]+[a-z0-9_]*/", $className, $matches);
        assert($matchesCount > 1);
        unset($matches[0][count($matches[0]) - 1]);
        $folderName = implode('/', $matches[0]);
        #$className = strtolower(get_class($this));
        #$folderName = substr($className, 0, -strlen('Controller'));
        return APP_DIR . "views/$folderName/";
    }

    /**
     * Get a variable formatted for html code.
     *
     * @param string $name variable name
     * @return string
     * @throws Exception
     */
    public function htmlVar($name)
    {
        if (isset($this->$name))
        {
            return htmlspecialchars($this->$name, ENT_QUOTES, 'UTF-8');
        }
        else
        {
            throw new Exception(sprintf(_("Variable %s is missing.", $name)));
        }
    }

    /**
     * Default action.
     *
     * @return mixed
     */
    abstract function actionIndex();
}