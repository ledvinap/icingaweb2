<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Form\Config\Authentication;

use Exception;
use Icinga\Data\ResourceFactory;
use Icinga\Exception\ConfigurationError;
use Icinga\Authentication\Backend\LdapUserBackend;

/**
 * Form for adding or modifying LDAP authentication backends
 */
class LdapBackendForm extends BaseBackendForm
{
    /**
     * The available ldap resources prepared to be used as select input data
     *
     * @var array
     */
    protected $resources;

    /**
     * Initialize this form
     *
     * Populates $this->resources.
     *
     * @throws  ConfigurationError  In case no database resources can be found
     */
    public function init()
    {
        $ldapResources = array_keys(
            ResourceFactory::getResourceConfigs('ldap')->toArray()
        );

        if (empty($ldapResources)) {
            throw new ConfigurationError(
                t('There are no LDAP resources')
            );
        }

        // array_combine() is necessary in order to use the array as select input data
        $this->resources = array_combine($ldapResources, $ldapResources);
    }

    /**
     * @see Form::createElements()
     */
    public function createElements(array $formData)
    {
        return array(
            $this->createElement(
                'text',
                'name',
                array(
                    'required'      => true,
                    'label'         => t('Backend Name'),
                    'helptext'      => t('The name of this authentication backend')
                )
            ),
            $this->createElement(
                'select',
                'resource',
                array(
                    'required'      => true,
                    'label'         => t('LDAP Resource'),
                    'helptext'      => t('The resource to use for authenticating with this provider'),
                    'multiOptions'  => $this->resources
                )
            ),
            $this->createElement(
                'text',
                'user_class',
                array(
                    'required'  => true,
                    'label'     => t('LDAP User Object Class'),
                    'helptext'  => t('The object class used for storing users on the ldap server'),
                    'value'     => 'inetOrgPerson'
                )
            ),
            $this->createElement(
                'text',
                'user_name_attribute',
                array(
                    'required'  => true,
                    'label'     => t('LDAP User Name Attribute'),
                    'helptext'  => t('The attribute name used for storing the user name on the ldap server'),
                    'value'     => 'uid'
                )
            ),
            $this->createElement(
                'hidden',
                'backend',
                array(
                    'required'  => true,
                    'value'     => 'ldap'
                )
            )
        );
    }

    /**
     * Validate the current configuration by connecting to a backend and requesting the user count
     *
     * @return  bool    Whether validation succeeded or not
     *
     * @see BaseBackendForm::isValidAuthenticationBacken()
     */
    public function isValidAuthenticationBackend()
    {
        if (false === ResourceFactory::ldapAvailable()) {
            // It should be possible to run icingaweb without the php ldap extension. When the user
            // tries to create an ldap backend without ldap being installed we display an error.
            $this->addErrorMessage(t('Using ldap is not possible, the php extension "ldap" is not installed.'));
            return false;
        }

        try {
            $backend = ResourceFactory::createResource(
                ResourceFactory::getResourceConfig($this->getValue('resource'))
            );
            $testConn = new LdapUserBackend(
                $backend,
                $this->getValue('user_class'),
                $this->getValue('user_name_attribute')
            );
            $testConn->assertAuthenticationPossible();
        } catch (Exception $exc) {
            $this->addErrorMessage(sprintf(t('Connection validation failed: %s'), $exc->getMessage()));
            return false;
        }

        return true;
    }
}
