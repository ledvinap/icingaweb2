<?php

/* Icinga Web 2 | (c) 2013-2022 Icinga GmbH | GPLv2+ */

namespace Icinga\Forms\Dashboard;

use Exception;
use Icinga\Application\Logger;
use Icinga\Web\Dashboard\Common\BaseDashboard;
use Icinga\Web\Dashboard\Dashboard;
use Icinga\Web\Dashboard\DashboardHome;
use Icinga\Web\Notification;
use Icinga\Web\Dashboard\Dashlet;
use Icinga\Web\Dashboard\Pane;
use ipl\Html\HtmlElement;
use ipl\Web\Url;

class DashletForm extends BaseDashboardForm
{
    protected function assemble()
    {
        $requestUrl = Url::fromRequest();

        $homes = $this->dashboard->getEntryKeyTitleArr();
        $activeHome = $this->dashboard->getActiveHome();
        $currentHome = $requestUrl->getParam('home', reset($homes));
        $populatedHome = $this->getPopulatedValue('home', $currentHome);

        $panes = [];
        if ($currentHome === $populatedHome && $populatedHome !== self::CREATE_NEW_HOME) {
            if (! $currentHome || ! $activeHome) {
                // Home param isn't passed through, so let's try to load based on the first home
                $firstHome = $this->dashboard->rewindEntries();
                if ($firstHome) {
                    $this->dashboard->loadDashboardEntries($firstHome->getName());

                    $panes = $firstHome->getEntryKeyTitleArr();
                }
            } else {
                $panes = $activeHome->getEntryKeyTitleArr();
            }
        } elseif ($this->dashboard->hasEntry($populatedHome)) {
            $this->dashboard->loadDashboardEntries($populatedHome);

            $panes = $this->dashboard->getActiveHome()->getEntryKeyTitleArr();
        }

        $this->addElement('hidden', 'org_pane', ['required' => false]);
        $this->addElement('hidden', 'org_home', ['required' => false]);
        $this->addElement('hidden', 'org_dashlet', ['required' => false]);

        $this->addElement('select', 'home', [
            'class'        => 'autosubmit',
            'required'     => true,
            'disabled'     => empty($homes) ?: null,
            'value'        => $populatedHome,
            'multiOptions' => array_merge([self::CREATE_NEW_HOME => self::CREATE_NEW_HOME], $homes),
            'label'        => t('Select Home'),
            'descriptions' => t('Select a dashboard home you want to add the dashboard pane to.')
        ]);

        if (empty($homes) || $populatedHome === self::CREATE_NEW_HOME) {
            $this->addElement('text', 'new_home', [
                'required'    => true,
                'label'       => t('Home Title'),
                'placeholder' => t('Enter dashboard home title'),
                'description' => t('Enter a title for the new dashboard home.')
            ]);
        }

        $populatedPane = $this->getPopulatedValue('pane');
        // Pane element's values are depending on the home element's value
        if ($populatedPane !== self::CREATE_NEW_PANE && ! in_array($populatedPane, $panes)) {
            $this->clearPopulatedValue('pane');
        }

        $populatedPane = $this->getPopulatedValue('pane', reset($panes));
        $disable = empty($panes) || $populatedHome === self::CREATE_NEW_HOME;
        $this->addElement('select', 'pane', [
            'class'        => 'autosubmit',
            'required'     => true,
            'disabled'     => $disable ?: null,
            'value'        => ! $disable ? $populatedPane : self::CREATE_NEW_PANE, // Cheat the browser complains
            'multiOptions' => array_merge([self::CREATE_NEW_PANE => self::CREATE_NEW_PANE], $panes),
            'label'        => t('Select Dashboard'),
            'description'  => t('Select a dashboard you want to add the dashlet to.'),
        ]);

        if ($disable || $this->getPopulatedValue('pane') === self::CREATE_NEW_PANE) {
            $this->addElement('text', 'new_pane', [
                'required'    => true,
                'label'       => t('Dashboard Title'),
                'placeholder' => t('Enter dashboard title'),
                'description' => t('Enter a title for the new dashboard.'),
            ]);
        }

        $this->addHtml(new HtmlElement('hr'));

        $this->addElement('textarea', 'url', [
            'required'    => true,
            'label'       => t('Url'),
            'placeholder' => t('Enter dashlet url'),
            'description' => t(
                'Enter url to be loaded in the dashlet. You can paste the full URL, including filters.'
            ),
        ]);

        $this->addElement('text', 'dashlet', [
            'required'    => true,
            'label'       => t('Dashlet Title'),
            'placeholder' => t('Enter a dashlet title'),
            'description' => t('Enter a title for the dashlet.'),
        ]);

        $removeButton = null;
        if ($requestUrl->getPath() === Dashboard::BASE_ROUTE . '/edit-dashlet') {
            $targetUrl = (clone $requestUrl)->setPath(Dashboard::BASE_ROUTE . '/remove-dashlet');
            $removeButton = $this->createRemoveButton($targetUrl, t('Remove Dashlet'));
        }

        $formControls = $this->createFormControls();
        $formControls->add([
            $this->registerSubmitButton(t('Add to Dashboard')),
            $removeButton,
            $this->createCancelButton()
        ]);

        $this->addHtml($formControls);
    }

    protected function onSuccess()
    {
        $conn = Dashboard::getConn();
        $dashboard = $this->dashboard;

        $selectedHome = $this->getPopulatedValue('home');
        if (! $selectedHome || $selectedHome === self::CREATE_NEW_HOME) {
            $selectedHome = $this->getPopulatedValue('new_home');
        }

        $selectedPane = $this->getPopulatedValue('pane');
        // If "pane" element is disabled, there will be no populated value for it
        if (! $selectedPane || $selectedPane === self::CREATE_NEW_PANE) {
            $selectedPane = $this->getPopulatedValue('new_pane');
        }

        if (Url::fromRequest()->getPath() === Dashboard::BASE_ROUTE . '/new-dashlet') {
            $currentHome = new DashboardHome($selectedHome);
            if ($dashboard->hasEntry($currentHome->getName())) {
                $currentHome = clone $dashboard->getEntry($currentHome->getName());
                if ($currentHome->getName() !== $dashboard->getActiveHome()->getName()) {
                    $currentHome->setActive();
                    $currentHome->loadDashboardEntries();
                }
            }

            $currentPane = new Pane($selectedPane);
            if ($currentHome->hasEntry($currentPane->getName())) {
                $currentPane = clone $currentHome->getEntry($currentPane->getName());
            }

            $dashlet = new Dashlet($this->getValue('dashlet'), $this->getValue('url'), $currentPane);
            if ($currentPane->hasEntry($dashlet->getName())) {
                Notification::error(sprintf(
                    t('Dashlet "%s" already exists within the "%s" dashboard pane'),
                    $dashlet->getTitle(),
                    $currentPane->getTitle()
                ));

                return;
            }

            $conn->beginTransaction();

            try {
                $dashboard->manageEntry($currentHome);
                $currentHome->manageEntry($currentPane);
                $currentPane->manageEntry($dashlet);

                $conn->commitTransaction();
            } catch (Exception $err) {
                Logger::error($err);
                $conn->rollBackTransaction();

                throw $err;
            }

            Notification::success(sprintf(t('Created dashlet "%s" successfully'), $dashlet->getTitle()));
        } else {
            $orgHome = $dashboard->getEntry($this->getValue('org_home'));
            $orgPane = $orgHome->getEntry($this->getValue('org_pane'));
            $orgDashlet = $orgPane->getEntry($this->getValue('org_dashlet'));

            $currentHome = new DashboardHome($selectedHome);
            if ($dashboard->hasEntry($currentHome->getName())) {
                $currentHome = clone $dashboard->getEntry($currentHome->getName());
                $activeHome = $dashboard->getActiveHome();
                if ($currentHome->getName() !== $activeHome->getName()) {
                    $currentHome->setActive();
                    $currentHome->loadDashboardEntries();
                }
            }

            $currentPane = new Pane($selectedPane);
            if ($currentHome->hasEntry($currentPane->getName())) {
                $currentPane = clone $currentHome->getEntry($currentPane->getName());
            }

            $currentPane->setHome($currentHome);
            // When the user wishes to create a new dashboard pane, we have to explicitly reset the dashboard panes
            // of the original home, so that it isn't considered as we want to move the pane even though it isn't
            // supposed to when the original home contains a dashboard with the same name
            // @see DashboardHome::managePanes() for details
            $selectedPane = $this->getPopulatedValue('pane');
            if ((! $selectedPane || $selectedPane === self::CREATE_NEW_PANE)
                && ! $currentHome->hasEntry($currentPane->getName())) {
                $orgHome->setEntries([]);
            }

            $currentDashlet = clone $orgDashlet;
            $currentDashlet
                ->setPane($currentPane)
                ->setUrl($this->getValue('url'))
                ->setTitle($this->getValue('dashlet'));

            if ($orgPane->getName() !== $currentPane->getName()
                && $currentPane->hasEntry($currentDashlet->getName())) {
                Notification::error(sprintf(
                    t('Failed to move dashlet "%s": Dashlet already exists within the "%s" dashboard pane'),
                    $currentDashlet->getTitle(),
                    $currentPane->getTitle()
                ));

                return;
            }

            $paneDiff = array_filter(array_diff_assoc($currentPane->toArray(), $orgPane->toArray()));
            $dashletDiff = array_filter(
                array_diff_assoc($currentDashlet->toArray(), $orgDashlet->toArray()),
                function ($val) {
                    return $val !== null;
                }
            );

            // Prevent meaningless updates when there weren't any changes,
            // e.g. when the user just presses the update button without changing anything
            if (empty($dashletDiff) && empty($paneDiff)) {
                return;
            }

            if (empty($paneDiff)) {
                // No dashboard diff means the dashlet is still in the same pane, so just
                // reset the dashlets of the original pane
                $orgPane->setEntries([]);
            }

            $conn->beginTransaction();

            try {
                $dashboard->manageEntry($currentHome);
                $currentHome->manageEntry($currentPane, $orgHome);
                $currentPane->manageEntry($currentDashlet, $orgPane);

                $conn->commitTransaction();
            } catch (Exception $err) {
                Logger::error($err);
                $conn->rollBackTransaction();

                throw $err;
            }

            Notification::success(sprintf(t('Updated dashlet "%s" successfully'), $currentDashlet->getTitle()));
        }
    }

    public function load(BaseDashboard $dashlet)
    {
        $home = Url::fromRequest()->getParam('home');
        /** @var Dashlet $dashlet */
        $this->populate(array(
            'org_home'    => $home,
            'org_pane'    => $dashlet->getPane()->getName(),
            'org_dashlet' => $dashlet->getName(),
            'dashlet'     => $dashlet->getTitle(),
            'url'         => $dashlet->getUrl()->getRelativeUrl()
        ));
    }
}
