<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2017 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao;

use Contao\CoreBundle\DataContainer\DcaFilterInterface;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\InternalServerErrorException;
use Contao\Image\ResizeConfiguration;
use Imagine\Gd\Imagine;


/**
 * Provide methods to handle data container arrays.
 *
 * @property integer $id
 * @property string  $table
 * @property mixed   $value
 * @property string  $field
 * @property string  $inputName
 * @property string  $palette
 * @property object  $activeRecord
 * @property array   $rootIds
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
abstract class DataContainer extends \Backend
{

	/**
	 * Current ID
	 * @var integer
	 */
	protected $intId;

	/**
	 * Name of the current table
	 * @var string
	 */
	protected $strTable;

	/**
	 * Name of the current field
	 * @var string
	 */
	protected $strField;

	/**
	 * Name attribute of the current input field
	 * @var string
	 */
	protected $strInputName;

	/**
	 * Value of the current field
	 * @var mixed
	 */
	protected $varValue;

	/**
	 * Name of the current palette
	 * @var string
	 */
	protected $strPalette;

	/**
	 * IDs of all root records
	 * @var array
	 */
	protected $root;

	/**
	 * WHERE clause of the database query
	 * @var array
	 */
	protected $procedure = array();

	/**
	 * Values for the WHERE clause of the database query
	 * @var array
	 */
	protected $values = array();

	/**
	 * Form attribute "onsubmit"
	 * @var array
	 */
	protected $onsubmit = array();

	/**
	 * Reload the page after the form has been submitted
	 * @var boolean
	 */
	protected $noReload = false;

	/**
	 * Active record
	 * @var Model|FilesModel
	 */
	protected $objActiveRecord;

	/**
	 * True if one of the form fields is uploadable
	 * @var boolean
	 */
	protected $blnUploadable = false;

	/**
	 * The picker table
	 * @var string
	 */
	protected $strPickerTable;

	/**
	 * The picker field
	 * @var string
	 */
	protected $strPickerField;

	/**
	 * The picker ID
	 * @var integer
	 */
	protected $intPickerId;

	/**
	 * The picker value
	 * @var array
	 */
	protected $arrPickerValue = array();


	/**
	 * Set an object property
	 *
	 * @param string $strKey
	 * @param mixed  $varValue
	 */
	public function __set($strKey, $varValue)
	{
		switch ($strKey)
		{
			case 'activeRecord':
				$this->objActiveRecord = $varValue;
				break;

			default;
				$this->$strKey = $varValue; // backwards compatibility
				break;
		}
	}


	/**
	 * Return an object property
	 *
	 * @param string $strKey
	 *
	 * @return mixed
	 */
	public function __get($strKey)
	{
		switch ($strKey)
		{
			case 'id':
				return $this->intId;
				break;

			case 'table':
				return $this->strTable;
				break;

			case 'value':
				return $this->varValue;
				break;

			case 'field':
				return $this->strField;
				break;

			case 'inputName':
				return $this->strInputName;
				break;

			case 'palette':
				return $this->strPalette;
				break;

			case 'activeRecord':
				return $this->objActiveRecord;
				break;
		}

		return parent::__get($strKey);
	}


	/**
	 * Render a row of a box and return it as HTML string
	 *
	 * @param string $strPalette
	 *
	 * @return string
	 *
	 * @throws AccessDeniedException
	 * @throws \Exception
	 */
	protected function row($strPalette=null)
	{
		$arrData = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField];

		// Check if the field is excluded
		if ($arrData['exclude'])
		{
			throw new AccessDeniedException('Field "' . $this->strTable . '.' . $this->strField . '" is excluded from being edited.');
		}

		$xlabel = '';

		// Toggle line wrap (textarea)
		if ($arrData['inputType'] == 'textarea' && !isset($arrData['eval']['rte']))
		{
			$xlabel .= ' ' . \Image::getHtml('wrap.svg', $GLOBALS['TL_LANG']['MSC']['wordWrap'], 'title="' . \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['wordWrap']) . '" class="toggleWrap" onclick="Backend.toggleWrap(\'ctrl_'.$this->strInputName.'\')"');
		}

		// Add the help wizard
		if ($arrData['eval']['helpwizard'])
		{
			$xlabel .= ' <a href="contao/help.php?table='.$this->strTable.'&amp;field='.$this->strField.'" title="' . \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['helpWizard']) . '" onclick="Backend.openModalIframe({\'title\':\''.\StringUtil::specialchars(str_replace("'", "\\'", $arrData['label'][0])).'\',\'url\':this.href});return false">'.\Image::getHtml('about.svg', $GLOBALS['TL_LANG']['MSC']['helpWizard'], 'style="vertical-align:text-bottom"').'</a>';
		}

		// Add a custom xlabel
		if (is_array($arrData['xlabel']))
		{
			foreach ($arrData['xlabel'] as $callback)
			{
				if (is_array($callback))
				{
					$this->import($callback[0]);
					$xlabel .= $this->{$callback[0]}->{$callback[1]}($this);
				}
				elseif (is_callable($callback))
				{
					$xlabel .= $callback($this);
				}
			}
		}

		// Input field callback
		if (is_array($arrData['input_field_callback']))
		{
			$this->import($arrData['input_field_callback'][0]);

			return $this->{$arrData['input_field_callback'][0]}->{$arrData['input_field_callback'][1]}($this, $xlabel);
		}
		elseif (is_callable($arrData['input_field_callback']))
		{
			return $arrData['input_field_callback']($this, $xlabel);
		}

		/** @var Widget $strClass */
		$strClass = $GLOBALS['BE_FFL'][$arrData['inputType']];

		// Return if the widget class does not exists
		if (!class_exists($strClass))
		{
			return '';
		}

		$arrData['eval']['required'] = false;

		// Use strlen() here (see #3277)
		if ($arrData['eval']['mandatory'])
		{
			if (is_array($this->varValue))
			{
				if (empty($this->varValue))
				{
					$arrData['eval']['required'] = true;
				}
			}
			else
			{
				if (!strlen($this->varValue))
				{
					$arrData['eval']['required'] = true;
				}
			}
		}

		// Convert insert tags in src attributes (see #5965)
		if (isset($arrData['eval']['rte']) && strncmp($arrData['eval']['rte'], 'tiny', 4) === 0)
		{
			$this->varValue = \StringUtil::insertTagToSrc($this->varValue);
		}

		// Use raw request if set globally but allow opting out setting useRawRequestData to false explicitly
		$useRawGlobally = isset($GLOBALS['TL_DCA'][$this->strTable]['config']['useRawRequestData']) && $GLOBALS['TL_DCA'][$this->strTable]['config']['useRawRequestData'] === true;
		$notRawForField = isset($arrData['eval']['useRawRequestData']) && $arrData['eval']['useRawRequestData'] === false;

		if ($useRawGlobally && !$notRawForField)
		{
			$arrData['eval']['useRawRequestData'] = true;
		}

		/** @var Widget $objWidget */
		$objWidget = new $strClass($strClass::getAttributesFromDca($arrData, $this->strInputName, $this->varValue, $this->strField, $this->strTable, $this));

		$objWidget->xlabel = $xlabel;
		$objWidget->currentRecord = $this->intId;

		// Validate the field
		if (\Input::post('FORM_SUBMIT') == $this->strTable)
		{
			$suffix = ($this instanceof DC_Folder ? md5($this->intId) : $this->intId);
			$key = (\Input::get('act') == 'editAll') ? 'FORM_FIELDS_' . $suffix : 'FORM_FIELDS';

			// Calculate the current palette
			$postPaletteFields = implode(',', \Input::post($key));
			$postPaletteFields = array_unique(\StringUtil::trimsplit('[,;]', $postPaletteFields));

			// Compile the palette if there is none
			if ($strPalette === null)
			{
				$newPaletteFields = \StringUtil::trimsplit('[,;]', $this->getPalette());
			}
			else
			{
				// Use the given palette ($strPalette is an array in editAll mode)
				$newPaletteFields = is_array($strPalette) ? $strPalette : \StringUtil::trimsplit('[,;]', $strPalette);

				// Re-check the palette if the current field is a selector field
				if (isset($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__']) && in_array($this->strField, $GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__']))
				{
					// If the field value has changed, recompile the palette
					if ($this->varValue != \Input::post($this->strInputName))
					{
						$newPaletteFields = \StringUtil::trimsplit('[,;]', $this->getPalette());
					}
				}
			}

			// Adjust the names in editAll mode
			if (\Input::get('act') == 'editAll')
			{
				foreach ($newPaletteFields as $k=>$v)
				{
					$newPaletteFields[$k] = $v . '_' . $suffix;
				}

				if ($this->User->isAdmin)
				{
					$newPaletteFields['pid'] = 'pid_' . $suffix;
					$newPaletteFields['sorting'] = 'sorting_' . $suffix;
				}
			}

			$paletteFields = array_intersect($postPaletteFields, $newPaletteFields);

			// Deprecated since Contao 4.2, to be removed in Contao 5.0
			if (!isset($_POST[$this->strInputName]) && in_array($this->strInputName, $paletteFields))
			{
				@trigger_error('Using FORM_FIELDS has been deprecated and will no longer work in Contao 5.0. Make sure to always submit at least an empty string in your widget.', E_USER_DEPRECATED);
			}

			// Validate and save the field
			if (in_array($this->strInputName, $paletteFields) || \Input::get('act') == 'overrideAll')
			{
				$objWidget->validate();

				if ($objWidget->hasErrors())
				{
					// Skip mandatory fields on auto-submit (see #4077)
					if (\Input::post('SUBMIT_TYPE') != 'auto' || !$objWidget->mandatory || $objWidget->value != '')
					{
						$this->noReload = true;
					}
				}
				elseif ($objWidget->submitInput())
				{
					$varValue = $objWidget->value;

					// Sort array by key (fix for JavaScript wizards)
					if (is_array($varValue))
					{
						ksort($varValue);
						$varValue = serialize($varValue);
					}

					// Convert file paths in src attributes (see #5965)
					if ($varValue && isset($arrData['eval']['rte']) && strncmp($arrData['eval']['rte'], 'tiny', 4) === 0)
					{
						$varValue = \StringUtil::srcToInsertTag($varValue);
					}

					// Save the current value
					try
					{
						$this->save($varValue);
					}
					catch (\Exception $e)
					{
						$this->noReload = true;
						$objWidget->addError($e->getMessage());
					}
				}
			}
		}

		$wizard = '';
		$strHelpClass = '';

		// Date picker
		if ($arrData['eval']['datepicker'])
		{
			$rgxp = $arrData['eval']['rgxp'];
			$format = \Date::formatToJs(\Config::get($rgxp.'Format'));

			switch ($rgxp)
			{
				case 'datim':
					$time = ",\n        timePicker: true";
					break;

				case 'time':
					$time = ",\n        pickOnly: \"time\"";
					break;

				default:
					$time = '';
					break;
			}

			$strOnSelect = '';

			// Trigger the auto-submit function (see #8603)
			if ($arrData['eval']['submitOnChange'])
			{
				$strOnSelect = ",\n        onSelect: function() { Backend.autoSubmit(\"" . $this->strTable . "\"); }";
			}

			$wizard .= ' ' . \Image::getHtml('assets/datepicker/images/icon.svg', '', 'title="'.\StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['datepicker']).'" id="toggle_' . $objWidget->id . '" style="cursor:pointer"') . '
  <script>
    window.addEvent("domready", function() {
      new Picker.Date($("ctrl_' . $objWidget->id . '"), {
        draggable: false,
        toggle: $("toggle_' . $objWidget->id . '"),
        format: "' . $format . '",
        positionOffset: {x:-211,y:-209}' . $time . ',
        pickerClass: "datepicker_bootstrap",
        useFadeInOut: !Browser.ie' . $strOnSelect . ',
        startDay: ' . $GLOBALS['TL_LANG']['MSC']['weekOffset'] . ',
        titleFormat: "' . $GLOBALS['TL_LANG']['MSC']['titleFormat'] . '"
      });
    });
  </script>';
		}

		// Color picker
		if ($arrData['eval']['colorpicker'])
		{
			// Support single fields as well (see #5240)
			$strKey = $arrData['eval']['multiple'] ? $this->strField . '_0' : $this->strField;

			$wizard .= ' ' . \Image::getHtml('pickcolor.svg', $GLOBALS['TL_LANG']['MSC']['colorpicker'], 'title="'.\StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['colorpicker']).'" id="moo_' . $this->strField . '" style="cursor:pointer"') . '
  <script>
    window.addEvent("domready", function() {
      var cl = $("ctrl_' . $strKey . '").value.hexToRgb(true) || [255, 0, 0];
      new MooRainbow("moo_' . $this->strField . '", {
        id: "ctrl_' . $strKey . '",
        startColor: cl,
        imgPath: "assets/colorpicker/images/",
        onComplete: function(color) {
          $("ctrl_' . $strKey . '").value = color.hex.replace("#", "");
        }
      });
    });
  </script>';
		}

		// DCA picker
		if (isset($arrData['eval']['dcaPicker']) && (is_array($arrData['eval']['dcaPicker']) || $arrData['eval']['dcaPicker'] === true))
		{
			$params = array();

			if (is_array($arrData['eval']['dcaPicker']) && isset($arrData['eval']['dcaPicker']['do']))
			{
				$params['do'] = $arrData['eval']['dcaPicker']['do'];
			}

			$params['context'] = 'link';
			$params['target'] = $this->strTable.'.'.$this->strField.'.'.$this->intId;
			$params['value'] = $this->varValue;
			$params['popup'] = 1;

			if (is_array($arrData['eval']['dcaPicker']) && isset($arrData['eval']['dcaPicker']['context']))
			{
				$params['context'] = $arrData['eval']['dcaPicker']['context'];
			}

			$wizard .= ' <a href="' . ampersand(System::getContainer()->get('router')->generate('contao_backend_picker', $params)) . '" title="' . \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['pagepicker']) . '" id="pp_' . $this->strField . '">' . \Image::getHtml((is_array($arrData['eval']['dcaPicker']) && isset($arrData['eval']['dcaPicker']['icon']) ? $arrData['eval']['dcaPicker']['icon'] : 'pickpage.svg'), $GLOBALS['TL_LANG']['MSC']['pagepicker']) . '</a>
  <script>
    $("pp_' . $this->strField . '").addEvent("click", function(e) {
      e.preventDefault();
      Backend.openModalSelector({
        "title": "' . \StringUtil::specialchars(str_replace("'", "\\'", $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['label'][0])) . '",
        "url": this.href,
        "callback": function(table, value) {
          new Request.Contao({
            evalScripts: false,
            onSuccess: function(txt, json) {
              $("ctrl_' . $this->strInputName . '").value = (json.tag || json.content);
              this.set("href", this.get("href").replace(/&value=[^&]*/, "&value=" + (json.tag || json.content)));
            }.bind(this)
          }).post({"action":"processPickerSelection", "table":table, "value":value.join(","), "REQUEST_TOKEN":"' . REQUEST_TOKEN . '"});
        }.bind(this)
      });
    });
  </script>';
		}

		// Add a custom wizard
		if (is_array($arrData['wizard']))
		{
			foreach ($arrData['wizard'] as $callback)
			{
				if (is_array($callback))
				{
					$this->import($callback[0]);
					$wizard .= $this->{$callback[0]}->{$callback[1]}($this);
				}
				elseif (is_callable($callback))
				{
					$wizard .= $callback($this);
				}
			}
		}

		$objWidget->wizard = $wizard;

		// Set correct form enctype
		if ($objWidget instanceof \uploadable)
		{
			$this->blnUploadable = true;
		}

		if ($arrData['inputType'] != 'password')
		{
			$arrData['eval']['tl_class'] .= ' widget';
		}

		// Mark floated single checkboxes
		if ($arrData['inputType'] == 'checkbox' && !$arrData['eval']['multiple'] && strpos($arrData['eval']['tl_class'], 'w50') !== false)
		{
			$arrData['eval']['tl_class'] .= ' cbx';
		}
		elseif ($arrData['inputType'] == 'text' && $arrData['eval']['multiple'] && strpos($arrData['eval']['tl_class'], 'wizard') !== false)
		{
			$arrData['eval']['tl_class'] .= ' inline';
		}

		$updateMode = '';

		// Replace the textarea with an RTE instance
		if (!empty($arrData['eval']['rte']))
		{
			list ($file, $type) = explode('|', $arrData['eval']['rte'], 2);

			/** @var BackendTemplate|object $objTemplate */
			$objTemplate = new \BackendTemplate('be_' . $file);
			$objTemplate->selector = 'ctrl_' . $this->strInputName;
			$objTemplate->type = $type;

			// Deprecated since Contao 4.0, to be removed in Contao 5.0
			$objTemplate->language = \Backend::getTinyMceLanguage();

			$updateMode = $objTemplate->parse();

			unset($file, $type);
		}

		// Handle multi-select fields in "override all" mode
		elseif (\Input::get('act') == 'overrideAll' && ($arrData['inputType'] == 'checkbox' || $arrData['inputType'] == 'checkboxWizard') && $arrData['eval']['multiple'])
		{
			$updateMode = '
</div>
<div class="widget">
  <fieldset class="tl_radio_container">
  <legend>' . $GLOBALS['TL_LANG']['MSC']['updateMode'] . '</legend>
    <input type="radio" name="'.$this->strInputName.'_update" id="opt_'.$this->strInputName.'_update_1" class="tl_radio" value="add" onfocus="Backend.getScrollOffset()"> <label for="opt_'.$this->strInputName.'_update_1">' . $GLOBALS['TL_LANG']['MSC']['updateAdd'] . '</label><br>
    <input type="radio" name="'.$this->strInputName.'_update" id="opt_'.$this->strInputName.'_update_2" class="tl_radio" value="remove" onfocus="Backend.getScrollOffset()"> <label for="opt_'.$this->strInputName.'_update_2">' . $GLOBALS['TL_LANG']['MSC']['updateRemove'] . '</label><br>
    <input type="radio" name="'.$this->strInputName.'_update" id="opt_'.$this->strInputName.'_update_0" class="tl_radio" value="replace" checked="checked" onfocus="Backend.getScrollOffset()"> <label for="opt_'.$this->strInputName.'_update_0">' . $GLOBALS['TL_LANG']['MSC']['updateReplace'] . '</label>
  </fieldset>';
		}

		$strPreview = '';

		// Show a preview image (see #4948)
		if ($this->strTable == 'tl_files' && $this->strField == 'name' && $this->objActiveRecord !== null && $this->objActiveRecord->type == 'file')
		{
			$objFile = new \File($this->objActiveRecord->path);

			if ($objFile->isImage)
			{
				$blnCanResize = true;

				// Check the maximum width and height if the GDlib is used to resize images
				if (!$objFile->isSvgImage && \System::getContainer()->get('contao.image.imagine') instanceof Imagine)
				{
					$blnCanResize = $objFile->height <= \Config::get('gdMaxImgHeight') && $objFile->width <= \Config::get('gdMaxImgWidth');
				}

				$image = \Image::getPath('placeholder.svg');

				if ($blnCanResize)
				{
					if ($objFile->width > 699 || $objFile->height > 524 || !$objFile->width || !$objFile->height)
					{
						$image = rawurldecode(\System::getContainer()->get('contao.image.image_factory')->create(TL_ROOT . '/' . $objFile->path, array(699, 524, ResizeConfiguration::MODE_BOX))->getUrl(TL_ROOT));
					}
					else
					{
						$image = $objFile->path;
					}
				}

				$objImage = new \File($image);
				$ctrl = 'ctrl_preview_' . substr(md5($image), 0, 8);

				$strPreview = '
<div id="' . $ctrl . '" class="tl_edit_preview" data-original-width="' . $objFile->viewWidth . '" data-original-height="' . $objFile->viewHeight . '">
  <img src="' . $objImage->dataUri . '" width="' . $objImage->width . '" height="' . $objImage->height . '" alt="">
</div>';

				// Add the script to mark the important part
				if (basename($image) !== 'placeholder.svg')
				{
					$strPreview .= '<script>Backend.editPreviewWizard($(\'' . $ctrl . '\'));</script>';

					if (\Config::get('showHelp'))
					{
						$strPreview .= '<p class="tl_help tl_tip">' . $GLOBALS['TL_LANG'][$this->strTable]['edit_preview_help'] . '</p>';
					}

					$strPreview = '<div class="widget">' . $strPreview . '</div>';
				}
			}
		}

		return $strPreview . '
<div' . ($arrData['eval']['tl_class'] ? ' class="' . trim($arrData['eval']['tl_class']) . '"' : '') . '>' . $objWidget->parse() . $updateMode . (!$objWidget->hasErrors() ? $this->help($strHelpClass) : '') . '
</div>';
	}


	/**
	 * Return the field explanation as HTML string
	 *
	 * @param string $strClass
	 *
	 * @return string
	 */
	public function help($strClass='')
	{
		$return = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['label'][1];

		if (!\Config::get('showHelp') || $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['inputType'] == 'password' || $return == '')
		{
			return '';
		}

		return '
  <p class="tl_help tl_tip' . $strClass . '">'.$return.'</p>';
	}


	/**
	 * Generate possible palette names from an array by taking the first value and either adding or not adding the following values
	 *
	 * @param array $names
	 *
	 * @return array
	 */
	protected function combiner($names)
	{
		$return = array('');
		$names = array_values($names);

		for ($i=0, $c=count($names); $i<$c; $i++)
		{
			$buffer = array();

			foreach ($return as $k=>$v)
			{
				$buffer[] = ($k%2 == 0) ? $v : $v.$names[$i];
				$buffer[] = ($k%2 == 0) ? $v.$names[$i] : $v;
			}

			$return = $buffer;
		}

		return array_filter($return);
	}


	/**
	 * Return a query string that switches into edit mode
	 *
	 * @param integer $id
	 *
	 * @return string
	 */
	protected function switchToEdit($id)
	{
		$arrKeys = array();
		$arrUnset = array('act', 'id', 'table');

		foreach (array_keys($_GET) as $strKey)
		{
			if (!in_array($strKey, $arrUnset))
			{
				$arrKeys[$strKey] = $strKey . '=' . \Input::get($strKey);
			}
		}

		$strUrl = TL_SCRIPT . '?' . implode('&', $arrKeys);

		return $strUrl . (!empty($arrKeys) ? '&' : '') . (\Input::get('table') ? 'table='.\Input::get('table').'&amp;' : '').'act=edit&amp;id='.rawurlencode($id);
	}


	/**
	 * Compile buttons from the table configuration array and return them as HTML
	 *
	 * @param array   $arrRow
	 * @param string  $strTable
	 * @param array   $arrRootIds
	 * @param boolean $blnCircularReference
	 * @param array   $arrChildRecordIds
	 * @param string  $strPrevious
	 * @param string  $strNext
	 *
	 * @return string
	 */
	protected function generateButtons($arrRow, $strTable, $arrRootIds=array(), $blnCircularReference=false, $arrChildRecordIds=null, $strPrevious=null, $strNext=null)
	{
		if (empty($GLOBALS['TL_DCA'][$strTable]['list']['operations']))
		{
			return '';
		}

		$return = '';

		foreach ($GLOBALS['TL_DCA'][$strTable]['list']['operations'] as $k=>$v)
		{
			$v = is_array($v) ? $v : array($v);
			$id = \StringUtil::specialchars(rawurldecode($arrRow['id']));

			$label = $v['label'][0] ?: $k;
			$title = sprintf($v['label'][1] ?: $k, $id);
			$attributes = ($v['attributes'] != '') ? ' ' . ltrim(sprintf($v['attributes'], $id, $id)) : '';

			// Add the key as CSS class
			if (strpos($attributes, 'class="') !== false)
			{
				$attributes = str_replace('class="', 'class="' . $k . ' ', $attributes);
			}
			else
			{
				$attributes = ' class="' . $k . '"' . $attributes;
			}

			// Call a custom function instead of using the default button
			if (is_array($v['button_callback']))
			{
				$this->import($v['button_callback'][0]);
				$return .= $this->{$v['button_callback'][0]}->{$v['button_callback'][1]}($arrRow, $v['href'], $label, $title, $v['icon'], $attributes, $strTable, $arrRootIds, $arrChildRecordIds, $blnCircularReference, $strPrevious, $strNext, $this);
				continue;
			}
			elseif (is_callable($v['button_callback']))
			{
				$return .= $v['button_callback']($arrRow, $v['href'], $label, $title, $v['icon'], $attributes, $strTable, $arrRootIds, $arrChildRecordIds, $blnCircularReference, $strPrevious, $strNext, $this);
				continue;
			}

			// Generate all buttons except "move up" and "move down" buttons
			if ($k != 'move' && $v != 'move')
			{
				if ($k == 'show')
				{
					$return .= '<a href="'.$this->addToUrl($v['href'].'&amp;id='.$arrRow['id'].'&amp;popup=1').'" title="'.\StringUtil::specialchars($title).'" onclick="Backend.openModalIframe({\'title\':\''.\StringUtil::specialchars(str_replace("'", "\\'", sprintf($GLOBALS['TL_LANG'][$strTable]['show'][1], $arrRow['id']))).'\',\'url\':this.href});return false"'.$attributes.'>'.\Image::getHtml($v['icon'], $label).'</a> ';
				}
				else
				{
					$return .= '<a href="'.$this->addToUrl($v['href'].'&amp;id='.$arrRow['id'].(\Input::get('nb') ? '&amp;nc=1' : '')).'" title="'.\StringUtil::specialchars($title).'"'.$attributes.'>'.\Image::getHtml($v['icon'], $label).'</a> ';
				}

				continue;
			}

			$arrDirections = array('up', 'down');
			$arrRootIds = is_array($arrRootIds) ? $arrRootIds : array($arrRootIds);

			foreach ($arrDirections as $dir)
			{
				$label = $GLOBALS['TL_LANG'][$strTable][$dir][0] ?: $dir;
				$title = $GLOBALS['TL_LANG'][$strTable][$dir][1] ?: $dir;

				$label = \Image::getHtml($dir.'.svg', $label);
				$href = $v['href'] ?: '&amp;act=move';

				if ($dir == 'up')
				{
					$return .= ((is_numeric($strPrevious) && (!in_array($arrRow['id'], $arrRootIds) || empty($GLOBALS['TL_DCA'][$strTable]['list']['sorting']['root']))) ? '<a href="'.$this->addToUrl($href.'&amp;id='.$arrRow['id']).'&amp;sid='.intval($strPrevious).'" title="'.\StringUtil::specialchars($title).'"'.$attributes.'>'.$label.'</a> ' : \Image::getHtml('up_.svg')).' ';
				}
				else
				{
					$return .= ((is_numeric($strNext) && (!in_array($arrRow['id'], $arrRootIds) || empty($GLOBALS['TL_DCA'][$strTable]['list']['sorting']['root']))) ? '<a href="'.$this->addToUrl($href.'&amp;id='.$arrRow['id']).'&amp;sid='.intval($strNext).'" title="'.\StringUtil::specialchars($title).'"'.$attributes.'>'.$label.'</a> ' : \Image::getHtml('down_.svg')).' ';
				}
			}
		}

		return trim($return);
	}


	/**
	 * Compile global buttons from the table configuration array and return them as HTML
	 *
	 * @return string
	 */
	protected function generateGlobalButtons()
	{
		if (!is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations']))
		{
			return '';
		}

		$return = '';

		foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations'] as $k=>$v)
		{
			if (\Input::get('act') == 'select' && !$v['showOnSelect'])
			{
				continue;
			}

			$v = is_array($v) ? $v : array($v);
			$label = is_array($v['label']) ? $v['label'][0] : $v['label'];
			$title = is_array($v['label']) ? $v['label'][1] : $v['label'];
			$attributes = ($v['attributes'] != '') ? ' ' . ltrim($v['attributes']) : '';

			// Custom icon (see #5541)
			if ($v['icon'])
			{
				$v['class'] = trim($v['class'] . ' header_icon');

				// Add the theme path if only the file name is given
				if (strpos($v['icon'], '/') === false)
				{
					$v['icon'] = \Image::getPath($v['icon']);
				}

				$attributes = sprintf('style="background-image:url(\'%s%s\')"', TL_ASSETS_URL, $v['icon']) . $attributes;
			}

			if ($label == '')
			{
				$label = $k;
			}
			if ($title == '')
			{
				$title = $label;
			}

			// Call a custom function instead of using the default button
			if (is_array($v['button_callback']))
			{
				$this->import($v['button_callback'][0]);
				$return .= $this->{$v['button_callback'][0]}->{$v['button_callback'][1]}($v['href'], $label, $title, $v['class'], $attributes, $this->strTable, $this->root);
				continue;
			}
			elseif (is_callable($v['button_callback']))
			{
				$return .= $v['button_callback']($v['href'], $label, $title, $v['class'], $attributes, $this->strTable, $this->root);
				continue;
			}

			$return .= '<a href="'.$this->addToUrl($v['href']).'" class="'.$v['class'].'" title="'.\StringUtil::specialchars($title).'"'.$attributes.'>'.$label.'</a> ';
		}

		return $return;
	}


	/**
	 * Initialize the picker
	 *
	 * @return boolean
	 *
	 * @throws InternalServerErrorException
	 */
	protected function initPicker()
	{
		$menuBuilder = \System::getContainer()->get('contao.menu.picker_menu_builder');

		if (!$menuBuilder->supportsTable($this->strTable))
		{
			return false;
		}

		list($this->strPickerTable, $this->strPickerField, $this->intPickerId) = explode('.', \Input::get('target'), 3);
		$this->intPickerId = (int) $this->intPickerId;

		\Controller::loadDataContainer($this->strPickerTable);

		$this->setPickerValue();

		$strDriver = 'DC_' . $GLOBALS['TL_DCA'][$this->strPickerTable]['config']['dataContainer'];
		$objDca = new $strDriver($this->strPickerTable);
		$objDca->id = $this->intPickerId;
		$objDca->field = $this->strPickerField;

		// Set the active record
		if ($this->intPickerId && $this->Database->tableExists($this->strPickerTable))
		{
			/** @var Model $strModel */
			$strModel = \Model::getClassFromTable($this->strPickerTable);

			if (class_exists($strModel))
			{
				$objModel = $strModel::findByPk($this->intPickerId);

				if ($objModel !== null)
				{
					$objDca->activeRecord = $objModel;
				}
			}
		}

		// Call the load_callback
		if (is_array($GLOBALS['TL_DCA'][$this->strPickerTable]['fields'][$this->strPickerField]['load_callback']))
		{
			foreach ($GLOBALS['TL_DCA'][$this->strPickerTable]['fields'][$this->strPickerField]['load_callback'] as $callback)
			{
				if (is_array($callback))
				{
					$this->import($callback[0]);
					$this->arrPickerValue = $this->{$callback[0]}->{$callback[1]}($this->arrPickerValue, $objDca);
				}
				elseif (is_callable($callback))
				{
					$this->arrPickerValue = $callback($this->arrPickerValue, $objDca);
				}
			}
		}

		if (!isset($GLOBALS['TL_DCA'][$this->strPickerTable]['fields'][$this->strPickerField]))
		{
			throw new InternalServerErrorException('Target field "' . $this->strPickerTable . '.' . $this->strPickerField . '" does not exist.');
		}

		/** @var Widget $strClass */
		$strClass = $GLOBALS['BE_FFL'][$GLOBALS['TL_DCA'][$this->strPickerTable]['fields'][$this->strPickerField]['inputType']];

		/** @var Widget $objWidget */
		$objWidget = new $strClass($strClass::getAttributesFromDca($GLOBALS['TL_DCA'][$this->strPickerTable]['fields'][$this->strPickerField], $this->strPickerField, $this->arrPickerValue, $this->strPickerField, $this->strPickerTable, $objDca));

		if ($objWidget instanceof DcaFilterInterface)
		{
			$this->setDcaFilter($objWidget->getDcaFilter());
		}

		return true;
	}


	/**
	 * Set the picker value
	 */
	protected function setPickerValue()
	{
		$varValue = \Input::get('value');

		if (empty($varValue))
		{
			return;
		}

		$varValue = array_filter(explode(',', $varValue));

		if (empty($varValue))
		{
			return;
		}

		$this->arrPickerValue = $varValue;
	}


	/**
	 * Set the DCA filter
	 *
	 * @param array $arrFilter
	 */
	protected function setDcaFilter($arrFilter)
	{
		if (isset($arrFilter['root']))
		{
			$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root'] = $arrFilter['root'];
		}
	}


	/**
	 * Return the picker attributes
	 *
	 * @return string
	 */
	protected function getPickerAttributes()
	{
		if ($this->strPickerField)
		{
			return ' id="tl_select" data-table="' . $this->strTable . '"';
		}

		return '';
	}


	/**
	 * Return the picker input field markup
	 *
	 * @param string $value
	 * @param string $attributes
	 *
	 * @return string
	 */
	protected function getPickerInputField($value, $attributes='')
	{
		$id = is_numeric($value) ? $value : md5($value);

		switch ($GLOBALS['TL_DCA'][$this->strPickerTable]['fields'][$this->strPickerField]['eval']['fieldType'])
		{
			case 'checkbox':
				return ' <input type="checkbox" name="'.$this->strPickerField.'[]" id="'.$this->strPickerField.'_'.$id.'" class="tl_tree_checkbox" value="'.\StringUtil::specialchars($value).'" onfocus="Backend.getScrollOffset()"'.\Widget::optionChecked($value, $this->arrPickerValue).$attributes.'>';

			case 'radio':
				return ' <input type="radio" name="'.$this->strPickerField.'" id="'.$this->strPickerField.'_'.$id.'" class="tl_tree_radio" value="'.\StringUtil::specialchars($value).'" onfocus="Backend.getScrollOffset()"'.\Widget::optionChecked($value, $this->arrPickerValue).$attributes.'>';
		}

		return '';
	}


	/**
	 * Return the name of the current palette
	 *
	 * @return string
	 */
	abstract public function getPalette();

	/**
	 * Save the current value
	 *
	 * @param mixed $varValue
	 *
	 * @throws \Exception
	 */
	abstract protected function save($varValue);
}