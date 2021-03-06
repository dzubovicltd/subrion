<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2017 Intelliants, LLC <https://intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link https://subrion.org/
 *
 ******************************************************************************/

class iaBackendController extends iaAbstractControllerBackend
{
    protected $_name = 'plugins';

    protected $_processAdd = false;
    protected $_processEdit = false;

    protected $_phraseSaveError = 'plugin_status_may_not_be_changed';

    private $_folder;


    public function __construct()
    {
        parent::__construct();

        $iaModule = $this->_iaCore->factory('module', iaCore::ADMIN);

        $this->setHelper($iaModule);
        $this->setTable(iaModule::getTable());

        $this->_folder = IA_MODULES;
    }

    protected function _indexPage(&$iaView)
    {
        parent::_indexPage($iaView);

        $iaView->display($this->getName());
    }

    protected function _gridRead($params)
    {
        if (1 == count($this->_iaCore->requestPath)) {
            switch ($this->_iaCore->requestPath[0]) {
                case 'documentation':
                    return $this->_getDocumentation($params['name']);

                case 'install':
                case 'reinstall':
                case 'uninstall':
                    $action = $this->_iaCore->requestPath[0];
                    $iaAcl = $this->_iaCore->factory('acl');

                    if (!$iaAcl->isAccessible($this->getName(), $action)) {
                        return iaView::accessDenied();
                    }

                    $moduleName = $_POST['name'];

                    return ('uninstall' == $action)
                        ? $this->_uninstall($moduleName)
                        : $this->_install($moduleName, $action);
            }
        }

        $output = [];

        $start = isset($params['start']) ? (int)$params['start'] : 0;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 15;
        $sort = isset($params['sort']) ? $params['sort'] : '';
        $dir = in_array($params['dir'], [iaDb::ORDER_ASC, iaDb::ORDER_DESC]) ? $params['dir'] : iaDb::ORDER_ASC;
        $filter = empty($params['filter']) ? '' : $params['filter'];

        switch ($params['type']) {
            case 'installed':
                $output = $this->_getInstalledPlugins($start, $limit, $sort, $dir, $filter);
                break;

            case 'local':
                $output = $this->_getLocalPlugins($start, $limit, $sort, $dir, $filter);
                break;

            case 'remote':
                $output = $this->_getRemotePlugins($start, $limit, $sort, $dir, $filter);
        }

        return $output;
    }

    protected function _entryUpdate(array $entryData, $entryId)
    {
        $stmt = '`removable` = 1 AND `id` = :id';
        $this->_iaDb->bind($stmt, ['id' => (int)$entryId]);

        $result = (bool)$this->_iaDb->update($entryData, $stmt);

        empty($result) || $this->_iaCore->getConfig(true);

        return $result;
    }


    private function _getRemotePlugins($start, $limit, $sort, $dir, $filter)
    {
        $pluginsData = [];

        if ($cachedData = $this->_iaCore->iaCache->get('subrion_plugins', 3600, true)) {
            $pluginsData = $cachedData;
        } else {
            if ($response = iaUtil::getPageContent(iaUtil::REMOTE_TOOLS_URL . 'list/plugin/' . IA_VERSION)) {
                $response = json_decode($response, true);
                if (!empty($response['error'])) {
                    $this->addMessage($response['error']);
                } elseif ($response['total'] > 0) {
                    if (isset($response['extensions']) && is_array($response['extensions'])) {
                        $pluginsData = [];
                        $installedPlugins = $this->_iaDb->keyvalue(['name', 'version'],
                            iaDb::convertIds(iaModule::TYPE_PLUGIN, 'type'));

                        foreach ($response['extensions'] as $entry) {
                            $pluginInfo = (array)$entry;
                            $pluginInfo['install'] = 0;

                            // exclude installed plugins
                            if (!array_key_exists($pluginInfo['name'], $installedPlugins)) {
                                $pluginsData['pluginsList'][$pluginInfo['name']] = $pluginInfo['title'];

                                if (isset($pluginInfo['compatibility']) && version_compare($pluginInfo['compatibility'],
                                        IA_VERSION, '<=')
                                ) {
                                    $pluginInfo['install'] = 1;
                                }
                                $pluginInfo['date'] = gmdate(iaDb::DATE_FORMAT, $pluginInfo['date']);
                                $pluginInfo['file'] = $pluginInfo['name'];
                                $pluginInfo['readme'] = false;
                                $pluginInfo['reinstall'] = false;
                                $pluginInfo['uninstall'] = false;
                                $pluginInfo['remove'] = false;
                                $pluginInfo['removable'] = false;

                                $pluginsData['plugins'][$pluginInfo['name']] = $pluginInfo;
                            }
                        }

                        // cache well-formed results
                        $this->_iaCore->iaCache->write('subrion_plugins', $pluginsData);
                    } else {
                        $this->addMessage('error_incorrect_format_from_subrion');
                    }
                }
            } else {
                $this->addMessage('error_incorrect_response_from_subrion');
            }
        }

        return $this->getMessages()
            ? ['result' => false, 'message' => $this->getMessages()]
            : $this->_sortPlugins($pluginsData, $start, $limit, $dir, $filter, $sort);
    }

    private function _getLocalPlugins($start, $limit, $sort, $dir, $filter)
    {
        $total = 0;
        $pluginsData = [];
        $installedPlugins = $this->_iaDb->keyvalue(['name', 'version'],
            iaDb::convertIds(iaModule::TYPE_PLUGIN, 'type'));

        $directory = opendir($this->_folder);
        while ($file = readdir($directory)) {
            if (substr($file, 0, 1) != '.' && is_dir($this->_folder . $file)) {
                if (is_file($installationFile = $this->_folder . $file . IA_DS . iaModule::INSTALL_FILE_NAME)) {
                    if ($fileContent = file_get_contents($installationFile)) {
                        $this->getHelper()->setXml($fileContent);
                        $this->getHelper()->parse(true);

                        if (iaModule::TYPE_PLUGIN != $this->getHelper()->itemData['type']) {
                            continue;
                        }
                        /*
                                                $installationPossible = false;
                                                if (!$this->getHelper()->getNotes())
                                                {
                                                    $version = explode('-', $this->getHelper()->itemData['compatibility']);
                                                    if (!isset($version[1]))
                                                    {
                                                        if (version_compare($version[0], IA_VERSION, '<='))
                                                        {
                                                            $installationPossible = true;
                                                        }
                                                    }
                                                    else
                                                    {
                                                        if (version_compare($version[0], IA_VERSION, '<=')
                                                            && version_compare($version[1], IA_VERSION, '>='))
                                                        {
                                                            $installationPossible = true;
                                                        }
                                                    }
                                                }
                        */
                        if (!array_key_exists($this->getHelper()->itemData['name'], $installedPlugins)) {
                            $notes = $this->getHelper()->getNotes();
                            if ($notes) {
                                $notes = implode(PHP_EOL, $notes);
                                $notes .= PHP_EOL . PHP_EOL . iaLanguage::get('installation_impossible');
                            }

                            $pluginsData['pluginsList'][$this->getHelper()->itemData['name']] = $this->getHelper()->itemData['info']['title'];
                            $pluginsData['plugins'][$this->getHelper()->itemData['name']] = [
                                'title' => $this->getHelper()->itemData['info']['title'],
                                'version' => $this->getHelper()->itemData['info']['version'],
                                'compatibility' => $this->getHelper()->itemData['compatibility'],
                                'description' => $this->getHelper()->itemData['info']['summary'],
                                'author' => $this->getHelper()->itemData['info']['author'],
                                'date' => $this->getHelper()->itemData['info']['date'],
                                'name' => $this->getHelper()->itemData['name'],
                                'file' => $file,
                                'notes' => $notes,
                                'info' => true,
                                'install' => true
                            ];

                            $total++;
                        }
                    }
                }
            }
        }
        closedir($directory);

        return $this->_sortPlugins($pluginsData, $start, $limit, $dir, $filter, $sort);
    }

    private function _getInstalledPlugins($start, $limit, $sort, $dir, $filter)
    {
        $where = "`type` = '" . iaModule::TYPE_PLUGIN . "'" . (empty($filter) ? '' : " AND `title` LIKE '%{$filter}%'");
        $order = ($sort && $dir) ? " ORDER BY `{$sort}` {$dir}" : '';

        $result = [
            'data' => $this->_iaDb->all([
                'id',
                'name',
                'title',
                'version',
                'status',
                'author',
                'summary',
                'removable',
                'date'
            ], $where . $order, $start, $limit),
            'total' => $this->_iaDb->one(iaDb::STMT_COUNT_ROWS, $where)
        ];

        if ($result['data']) {
            foreach ($result['data'] as &$entry) {
                if ($row = $this->_iaDb->row_bind(['name', 'config_group'], '`module` = :plugin ORDER BY `order` ASC',
                    ['plugin' => $entry['name']], iaCore::getConfigTable())
                ) {
                    $entry['config'] = $row['config_group'] . '/#' . $row['name'] . '';
                }

                if ($alias = $this->_iaDb->one_bind('alias', '`name` = :name', ['name' => $entry['name']],
                    'admin_pages')
                ) {
                    $entry['manage'] = $alias;
                }

                $entry['file'] = $entry['name'];
                $entry['info'] = true;
                $entry['reinstall'] = true;
                $entry['uninstall'] = $entry['removable'];
                $entry['remove'] = $entry['removable'];

                if (is_dir(IA_MODULES . $entry['name'])) {
                    $installationFile = IA_MODULES . $entry['name'] . IA_DS . iaModule::INSTALL_FILE_NAME;

                    if (file_exists($installationFile)) {
                        $fileContent = file_get_contents($installationFile);

                        $this->getHelper()->setXml($fileContent);
                        $this->getHelper()->parse();

                        if (($this->getHelper()->itemData['compatibility'] && version_compare(IA_VERSION,
                                    $this->getHelper()->itemData['compatibility'],
                                    '>=')) && version_compare($this->getHelper()->itemData['info']['version'],
                                $entry['version'], '>')
                        ) {
                            $entry['upgrade'] = $entry['name'];
                        }

                        $entry['name'] = $this->getHelper()->itemData['name'];
                    }
                }
            }
        }

        return $result;
    }

    private function _sortPlugins(
        array $pluginsData,
        $start,
        $limit,
        $dir = iaDb::ORDER_DESC,
        $filter = '',
        $column = 'date'
    ) {
        $plugins = $pluginsData['plugins'];
        $output = ['data' => [], 'total' => count($plugins)];

        if ($plugins) {
            if ($filter) {
                foreach ($plugins as $plugin) {
                    if (false === stripos($plugin['name'] . $plugin['title'], $filter)) {
                        unset($plugins[$plugin['name']]);
                    }
                }

                $output['total'] = count($plugins);
            }
            if (iaDb::ORDER_ASC == $dir) {
                if ('date' == $column) {
                    usort($plugins, function ($a, $b) use ($column) {
                        return strtotime($a[$column]) - strtotime($b[$column]);
                    });
                } else {
                    usort($plugins, function ($a, $b) use ($column) {
                        return strcasecmp($a[$column], $b[$column]);
                    });
                }
            } else {
                if ('date' == $column) {
                    usort($plugins, function ($a, $b) use ($column) {
                        return strtotime($b[$column]) - strtotime($a[$column]);
                    });
                } else {
                    usort($plugins, function ($a, $b) use ($column) {
                        return strcasecmp($b[$column], $a[$column]);
                    });
                }
            }

            $plugins = array_splice($plugins, $start, $limit);

            $output['data'] = $plugins;
        }

        return $output;
    }

    private function _getDocumentation($moduleName)
    {
        $result = [];

        if (file_exists($documentationPath = IA_MODULES . $moduleName . IA_DS . 'docs' . IA_DS)) {
            $docs = scandir($documentationPath);

            foreach ($docs as $doc) {
                if (substr($doc, 0, 1) != '.' && is_file($documentationPath . $doc)) {
                    if (!is_null($contents = file_get_contents($documentationPath . $doc))) {
                        $contents = str_replace('{IA_URL}', IA_CLEAR_URL, $contents);
                        $tab = substr($doc, 0, count($doc) - 6);
                        $result['tabs'][] = [
                            'title' => iaLanguage::get('extra_' . $tab, $tab),
                            'html' => ('changelog' == $tab ? preg_replace('/#(\d+)/',
                                '<a href="https://dev.subrion.org/issues/$1" target="_blank">#$1</a>',
                                $contents) : $contents),
                            'cls' => 'extension-docs' . ' extension-docs--' . $tab
                        ];
                    }
                }
            }

            $this->getHelper()->setXml(file_get_contents($this->_folder . $moduleName . IA_DS . iaModule::INSTALL_FILE_NAME));
            $this->getHelper()->parse();

            $search = [
                '{icon}',
                '{link}',
                '{name}',
                '{author}',
                '{contributor}',
                '{version}',
                '{date}',
                '{compatibility}'
            ];

            $data = $this->getHelper()->itemData;
            $icon = file_exists(IA_MODULES . $moduleName . IA_DS . 'docs' . IA_DS . 'img' . IA_DS . 'icon.png')
                ? '<tr><td class="plugin-icon"><img src="' . $this->_iaCore->iaView->assetsUrl . 'modules/' . $moduleName . '/docs/img/icon.png" alt="' . $data['info']['title'] . '"></td></tr>'
                : '';
            $link = '<tr><td><a href="https://subrion.org/plugin/' . $moduleName . '.html" class="btn btn-block btn-info" target="_blank">Additional info</a><br></td></tr>';

            $replace = [
                $icon,
                $link,
                $data['info']['title'],
                $data['info']['author'],
                $data['info']['contributor'],
                $data['info']['version'],
                $data['info']['date'],
                $data['compatibility']
            ];

            $template = file_get_contents(IA_ADMIN . 'templates' . IA_DS . $this->_iaCore->get('admin_tmpl') . IA_DS . 'extra_information.tpl');

            $result['info'] = str_replace($search, $replace, $template);
        }

        return $result;
    }

    private function _install($moduleName, $action)
    {
        $result = ['error' => true];

        if (isset($_POST['mode']) && 'remote' == $_POST['mode']) {
            $modulesTempFolder = IA_TMP . 'modules' . IA_DS;
            is_dir($modulesTempFolder) || mkdir($modulesTempFolder);

            $filePath = $modulesTempFolder . $moduleName;
            $fileName = $filePath . '.zip';

            // save remote plugin file
            iaUtil::downloadRemoteContent(iaUtil::REMOTE_TOOLS_URL . 'install/' . $moduleName . IA_URL_DELIMITER . IA_VERSION,
                $fileName);

            if (file_exists($fileName)) {
                if (is_writable($this->_folder)) {
                    // delete previous folder
                    if (is_dir($this->_folder . $moduleName)) {
                        unlink($this->_folder . $moduleName);
                    }

                    include_once(IA_INCLUDES . 'utils' . IA_DS . 'pclzip.lib.php');

                    $pclZip = new PclZip($fileName);
                    $pclZip->extract(PCLZIP_OPT_PATH, IA_MODULES . $moduleName);

                    $this->_iaCore->iaCache->remove('subrion_plugins');
                } else {
                    $result['message'] = iaLanguage::get('upload_module_error');
                }
            }
        }

        $iaModule = $this->getHelper();

        $installationFile = $this->_folder . $moduleName . IA_DS . iaModule::INSTALL_FILE_NAME;
        if (!file_exists($installationFile)) {
            $result['message'] = iaLanguage::get('file_doesnt_exist');
        } else {
            $iaModule->setXml(file_get_contents($installationFile));
            $result['error'] = false;
        }

        $iaModule->parse();

        $installationPossible = false;
        $version = explode('-', $iaModule->itemData['compatibility']);
        if (!isset($version[1])) {
            if (version_compare($version[0], IA_VERSION, '<=')) {
                $installationPossible = true;
            }
        } else {
            if (version_compare($version[0], IA_VERSION, '<=')
                && version_compare($version[1], IA_VERSION, '>=')
            ) {
                $installationPossible = true;
            }
        }

        if (!$installationPossible) {
            $result['message'] = iaLanguage::get('incompatible');
            $result['error'] = true;
        }

        if (!$result['error']) {
            $iaModule->doAction(iaModule::ACTION_INSTALL);
            if ($iaModule->error) {
                $result['message'] = $iaModule->getMessage();
                $result['error'] = true;
            } else {
                $iaLog = $this->_iaCore->factory('log');

                if ($iaModule->isUpgrade) {
                    $result['message'] = iaLanguage::get('plugin_updated');

                    $iaLog->write(iaLog::ACTION_UPGRADE, [
                        'type' => iaModule::TYPE_PLUGIN,
                        'name' => $iaModule->itemData['info']['title'],
                        'to' => $iaModule->itemData['info']['version']
                    ]);
                } else {
                    $result['groups'] = $iaModule->getMenuGroups();
                    $result['message'] = (iaModule::ACTION_INSTALL == $action)
                        ? iaLanguage::getf('plugin_installed', ['name' => $iaModule->itemData['info']['title']])
                        : iaLanguage::getf('plugin_reinstalled', ['name' => $iaModule->itemData['info']['title']]);

                    $iaLog->write(iaLog::ACTION_INSTALL,
                        ['type' => iaModule::TYPE_PLUGIN, 'name' => $iaModule->itemData['info']['title']]);
                }

                empty($iaModule->itemData['notes']) || $result['message'][] = $iaModule->itemData['notes'];

                $this->_iaCore->getConfig(true);
            }
        }

        $result['result'] = !$result['error'];
        unset($result['error']);

        return $result;
    }

    private function _uninstall($moduleName)
    {
        $result = ['result' => false, 'message' => iaLanguage::get('invalid_parameters')];

        if ($this->_iaDb->exists('`name` = :plugin AND `type` = :type AND `removable` = 1',
            ['plugin' => $moduleName, 'type' => iaModule::TYPE_PLUGIN])
        ) {
            $installationFile = $this->_folder . $moduleName . IA_DS . iaModule::INSTALL_FILE_NAME;

            if (!file_exists($installationFile)) {
                $result['message'] = [iaLanguage::get('plugin_files_physically_missed')];
            }

            $this->getHelper()->uninstall($moduleName);

            is_array($result['message'])
                ? $result['message'][] = iaLanguage::get('plugin_uninstalled')
                : $result['message'] = iaLanguage::get('plugin_uninstalled');

            $result['result'] = true;

            // log this event
            $iaLog = $this->_iaCore->factory('log');
            $iaLog->write(iaLog::ACTION_UNINSTALL, ['type' => iaModule::TYPE_PLUGIN, 'name' => $moduleName]);
            //

            $this->_iaCore->getConfig(true);
        } else {
            $result['message'] = iaLanguage::get('plugin_may_not_be_removed');
        }

        return $result;
    }
}
