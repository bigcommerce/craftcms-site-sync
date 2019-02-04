<?php
namespace Craft;

class LocaleSyncService extends BaseApplicationComponent
{
	private $_elementBeforeSave;
	private $_element;
	private $_elementSettings;

	public function getElementOptionsHtml(BaseElementModel $element)
	{
		$isNew = $element->id === null;
		$locales = array_keys($element->getLocales());
		$settings = craft()->plugins->getPlugin('localeSync')->getSettings();

		if ($isNew || count($locales) < 2) {
			return;
		}

		return craft()->templates->render('localesync/_cp/editRightPane', [
			'settings' => $settings,
			'localeId' => $element->locale,
		]);
	}

	public function getLocaleInputOptions($locales = null, $exclude = [])
	{
		$locales = $locales ?: craft()->i18n->getSiteLocales();
		$locales = array_map(function($locale) use ($exclude) {
			if (!$locale instanceof LocaleModel) {
				$locale = craft()->i18n->getLocaleById($locale);
			}

			if ($locale instanceof LocaleModel && !in_array($locale->id, $exclude)) {
				$locale = [
					'label' => $locale->name,
					'value' => $locale->id,
				];
			} else {
				$locale = null;
			}

			return $locale;
		}, $locales);

		return array_filter($locales);
	}

	public function syncElementContent(Event $event, $elementSettings)
	{
		$pluginSettings = craft()->plugins->getPlugin('localeSync')->getSettings();
		$this->_element = $event->params['element'];
		$this->_elementSettings = $elementSettings;

		// elementSettings will be null in HUD, where we want to continue with defaults
		if ($this->_elementSettings !== null && ($event->params['isNewElement'] || empty($this->_elementSettings['enabled']))) {
			return;
		}

		$this->_elementBeforeSave = craft()->elements->getElementById($this->_element->id, $this->_element->elementType, $this->_element->locale);
		$locales = $this->_element->getLocales();

		// Normalize getLocales() from different elementTypes
		if ($this->_element instanceof EntryModel) {
			$locales = array_keys($locales);
		}

		if (count($locales) < 2) {
			return;
		}

		$defaultTargets = array_key_exists($this->_element->locale, $pluginSettings->localeDefaults) ? $pluginSettings->localeDefaults[$this->_element->locale]['targets'] : [];
		$elementTargets = $this->_elementSettings['targets'];
		$targets = [];

		if (!empty($elementTargets)) {
			$targets = $elementTargets;
		} elseif (!empty($defaultTargets)) {
			$targets = $defaultTargets;
		}

		foreach ($locales as $localeId)
		{
			$localizedElement = craft()->elements->getElementById($this->_element->id, $this->_element->elementType, $localeId);
			$matchingTarget = $targets === '*' || in_array($localeId, $targets);
			$updates = false;

			if ($localizedElement && $matchingTarget && $this->_element->locale !== $localeId) {
				foreach ($localizedElement->getFieldLayout()->getFields() as $fieldLayoutField) {
					$field = $fieldLayoutField->getField();

					if ($this->updateElement($localizedElement, $field)) {
						$updates = true;
					}
				}

				if ($this->updateElement($localizedElement, 'title')) {
					$updates = true;
				}

			}

			if ($updates) {
				craft()->content->saveContent($localizedElement, false, false);

				if ($localizedElement instanceof EntryModel) {
					craft()->entryRevisions->saveVersion($localizedElement);
				}
			}
		}
	}

    public function updateElement(&$element, $field)
    {
        $fieldHandle = null;
        $translatable = false;
        $elementsField = false;
        $superTableFieldType = false;

        if ($field instanceof Fieldmodel) {
            $fieldHandle = $field->handle;
            $translatable = $field->translatable;
            $elementsField = $field->fieldType instanceof BaseElementFieldType;
            $superTableFieldType = $field->fieldType instanceof SuperTableFieldType;
        } elseif ($field === 'title') {
            $fieldHandle = $field;
            $translatable = true;
        }

        $matches = $this->_elementBeforeSave->content->$fieldHandle === $element->content->$fieldHandle;
        $overwrite = (isset($this->_elementSettings['overwrite']) && $this->_elementSettings['overwrite']);

        if ($elementsField) {
            $matches = $this->_elementBeforeSave->$fieldHandle->ids() === $element->$fieldHandle->ids();
        }

        if ($translatable && ($overwrite || $matches)) {

            if ($elementsField) {
                craft()->relations->saveRelations($field, $element, $this->_element->content->$fieldHandle);
            }

            if ($superTableFieldType && $this->canProcessSuperTable()) {
                //since this is passed by reference, we just need to run this and not worry about return.
                $element->getFieldValue($fieldHandle);

                $fieldValue = $this->_element->getFieldValue($fieldHandle);

                $superTableData = craft()->bcCore_superTable->buildSuperTableDataFromField($fieldHandle, $fieldValue);

                $field->fieldType->element->setContentFromPost($superTableData);

                craft()->superTable->saveField($field->fieldType);
            } else {
                $element->content->$fieldHandle = $this->_element->content->$fieldHandle;
            }

            return true;
        }

        return false;
    }

    private function canProcessSuperTable()
    {
        $bccore = craft()->plugins->getPlugin('bccore');
        $superTable = craft()->plugins->getPlugin('supertable');

        return (!empty($bccore) && $bccore->isEnabled) && (!empty($superTable) && $superTable->isEnabled);
    }
}
