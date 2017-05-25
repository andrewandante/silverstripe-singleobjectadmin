<?php

namespace SingleObjectAdmin;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\PjaxResponseNegotiator;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\Literalfield;

/**
 * Defines the Single Object Administration interface for the CMS
 *
 * @package SingleObjectAdmin
 * @author Jeremy Bridson with help from Stevie Mayhew
 */
class SingleObjectAdmin extends LeftAndMain implements PermissionProvider
{

    private static $url_rule = '/$Action/$ID/$OtherID';
    private static $menu_icon = 'silverstripe-singleobjectadmin/images/singleobjectadmin.png';

    private static $allowed_actions = array(
        'EditForm'
    );

    public function canView($member = null)
    {
        return Permission::check("CMS_ACCESS_SingleObjectAdmin");
    }

    public function providePermissions()
    {

        return array(
            "CMS_ACCESS_SingleObjectAdmin" => array(
                'name' => "Access to Single Object Administration",
                'category' => 'CMS Access',
                'help' => 'Allow use of Single Object Administration'
            )
        );
    }

    /**
     * @param null $id Not used.
     * @param null $fields Not used.
     * @return Form
     */
    public function getEditForm($id = null, $fields = null)
    {
        $objectClass = $this->config()->get('tree_class');

        $object = $objectClass::get()->first();
        if (!$object || !$object->exists()) {
            $currentReadingMode = Versioned::get_reading_mode();
            Versioned::set_reading_mode('Stage.Stage');
            $object = $objectClass::create();
            $object->write();
            if ($objectClass::has_extension('Versioned')) {
                $object->doPublish();
            }
            Versioned::set_reading_mode($currentReadingMode);
        }
        $fields = $object->getCMSFields();

        $fields->push(HiddenField::create('ID', 'ID', $object->ID));

        $fields->push($navField = new LiteralField('SilverStripeNavigator', $this->getSilverStripeNavigator()));
        $navField->setAllowHTML(true);

        $actions = new FieldList();
        $actions->push(
            FormAction::create('doSave', _t('GridFieldDetailForm.Save', 'Save'))
                ->setUseButtonTag(true)
                ->addExtraClass('ss-ui-action-constructive')
                ->setAttribute('data-icon', 'accept')
        );
        $form = Form::create(
            $this, 'EditForm', $fields, $actions
        )->setHTMLID('Form_EditForm');
        $form->addExtraClass('cms-content center cms-edit-form');
        if ($form->Fields()->hasTabset()) $form->Fields()->findOrMakeTab('Root')->setTemplate('CMSTabSet');
        $form->setHTMLID('Form_EditForm');
        $form->loadDataFrom($object);
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));

        // Use <button> to allow full jQuery UI styling
        $actions = $actions->dataFields();
        if ($actions) foreach ($actions as $action) $action->setUseButtonTag(true);

        $this->extend('updateEditForm', $form);

        return $form;

    }

    public function EditForm($request = null)
    {
        return $this->getEditForm();
    }

    /**
     * Used for preview controls, mainly links which switch between different states of the page.
     *
     * @return ArrayData
     */
    public function getSilverStripeNavigator()
    {
        return $this->renderWith('SingleObjectAdmin_SilverStripeNavigator');
    }

    /**
     * @return mixed
     */
    public function getResponseNegotiator()
    {
        $neg = parent::getResponseNegotiator();
        $controller = $this;
        $neg->setCallback('CurrentForm', function () use (&$controller) {
            return $controller->renderWith($controller->getTemplatesWithSuffix('_Content'));
        });
        return $neg;
    }

    /**
     * @return FieldList
     */
    public function getCMSActions()
    {
        $actions = new FieldList();
        $actions->push(
            FormAction::create('save_siteconfig', _t('CMSMain.SAVE', 'Save'))
                ->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')
        );
        $this->extend('updateCMSActions', $actions);

        return $actions;
    }

    /**
     * @param $data
     * @param $form
     * @return mixed
     */
    public function doSave($data, $form)
    {
        $objectClass = $this->config()->get('tree_class');
        $object = $objectClass::get()->byID($data['ID']);

        $currentReadingMode = Versioned::get_reading_mode();
        Versioned::set_reading_mode('Stage.Stage');

        $controller = Controller::curr();
        if (!$object->canEdit()) {
            return $controller->httpError(403);
        }

        try {
            $form->saveInto($object);
            $object->write();
        } catch (ValidationException $e) {
            $form->sessionMessage($e->getResult()->message(), 'bad');
            $responseNegotiator = new PjaxResponseNegotiator(array(
                'CurrentForm' => function () use (&$form) {
                    return $form->forTemplate();
                },
                'default' => function () use (&$controller) {
                    return $controller->redirectBack();
                }
            ));
            if ($controller->getRequest()->isAjax()) {
                $controller->getRequest()->addHeader('X-Pjax', 'CurrentForm');
            }
            return $responseNegotiator->respond($controller->getRequest());
        }

        Versioned::set_reading_mode($currentReadingMode);
        if ($objectClass::has_extension('Versioned')) {
            if ($object->isPublished()) {
                $this->publish($data, $form);
            }
        }

        $link = '"' . $object->i18n_singular_name() . '"';
        $message = _t(
            'GridFieldDetailForm.Saved',
            'Saved {name} {link}',
            array(
                'name' => $object->i18n_singular_name(),
                'link' => ''
            )
        );

        $form->sessionMessage($message, 'good');
        $action = $this->edit(Controller::curr()->getRequest());

        return $action;
    }

    /**
     * @param $request
     * @return mixed
     */
    public function edit($request)
    {
        $controller = Controller::curr();
        $form = $this->EditForm($request);

        $return = $this->customise(array(
            'Backlink' => $controller->hasMethod('Backlink') ? $controller->Backlink() : $controller->Link(),
            'EditForm' => $form,
        ))->renderWith('SingleObjectAdmin_Content');

        if ($request->isAjax()) {
            return $return;
        } else {
            return $controller->customise(array(
                'Content' => $return,
            ));
        }
    }

    /**
     * @param $data
     * @param $form
     */
    private function publish($data, $form)
    {
        $currentReadingMode = Versioned::get_reading_mode();
        Versioned::set_reading_mode('Stage.Stage');

        $objectClass = $this->config()->get('tree_class');

        $object = $objectClass::get()->byID($data['ID']);

        if ($object) {
            $object->doPublish();
            $form->sessionMessage($object->i18n_singular_name() . ' has been saved.', 'good');
        } else {
            $form->sessionMessage('Something failed, please refresh your browser.', 'bad');
        }

        Versioned::set_reading_mode($currentReadingMode);
    }

}
