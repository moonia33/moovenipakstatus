<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Moovenipakstatus extends Module
{
    public function __construct()
    {
        $this->name = 'moovenipakstatus';
        $this->tab = 'shipping_logistics';
        $this->version = '0.1.1';
        $this->author = 'moonia';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Venipak automatic order status');
        $this->description = $this->l('Automatically updates order statuses based on Venipak shipment status (mijoravenipak).');

        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        return parent::install()
            && $this->installConfiguration();
    }

    public function uninstall()
    {
        return $this->uninstallConfiguration() && parent::uninstall();
    }

    protected function installConfiguration()
    {
        $defaultScenarios = [
            [
                'enabled' => false,
                'source_state_id' => 0,
                'target_state_id' => 0,
                'venipak_statuses' => ['picked up', 'at terminal', 'out for delivery'],
            ],
            [
                'enabled' => false,
                'source_state_id' => 0,
                'target_state_id' => 0,
                'venipak_statuses' => ['venipak pickup point'],
            ],
            [
                'enabled' => false,
                'source_state_id' => 0,
                'target_state_id' => 0,
                'venipak_statuses' => ['delivered'],
            ],
        ];

        return Configuration::updateValue('MOOVENIPAK_AUTO_ENABLED', false)
            && Configuration::updateValue('MOOVENIPAK_AUTO_MAX_PER_RUN', 100)
            && Configuration::updateValue('MOOVENIPAK_AUTO_SCENARIOS', json_encode($defaultScenarios))
            && Configuration::updateValue('MOOVENIPAK_AUTO_TOKEN', Tools::passwdGen(32));
    }

    protected function uninstallConfiguration()
    {
        return Configuration::deleteByName('MOOVENIPAK_AUTO_ENABLED')
            && Configuration::deleteByName('MOOVENIPAK_AUTO_MAX_PER_RUN')
            && Configuration::deleteByName('MOOVENIPAK_AUTO_SCENARIOS')
            && Configuration::deleteByName('MOOVENIPAK_AUTO_TOKEN');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitMoovenipakstatus')) {
            $enabled = (bool) Tools::getValue('MOOVENIPAK_AUTO_ENABLED');
            $maxPerRun = (int) Tools::getValue('MOOVENIPAK_AUTO_MAX_PER_RUN');
            if ($maxPerRun <= 0) {
                $maxPerRun = 100;
            }

            $scenarios = [];
            for ($i = 1; $i <= 3; $i++) {
                $idx = (string) $i;
                $scenarios[] = [
                    'enabled' => (bool) Tools::getValue('scenario_'.$idx.'_enabled'),
                    'source_state_id' => (int) Tools::getValue('scenario_'.$idx.'_source_state_id'),
                    'target_state_id' => (int) Tools::getValue('scenario_'.$idx.'_target_state_id'),
                    'venipak_statuses' => $this->parseStatuses((string) Tools::getValue('scenario_'.$idx.'_statuses')),
                ];
            }

            Configuration::updateValue('MOOVENIPAK_AUTO_ENABLED', $enabled);
            Configuration::updateValue('MOOVENIPAK_AUTO_MAX_PER_RUN', $maxPerRun);
            Configuration::updateValue('MOOVENIPAK_AUTO_SCENARIOS', json_encode($scenarios));

            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output.$this->renderForm();
    }

    protected function parseStatuses($raw)
    {
        $result = [];
        $parts = preg_split('/[,\n;]/', (string) $raw);
        foreach ($parts as $p) {
            $p = trim(Tools::strtolower($p));
            if ($p !== '') {
                $result[] = $p;
            }
        }

        return array_values(array_unique($result));
    }

    protected function renderForm()
    {
        $defaultScenarios = [
            [
                'enabled' => false,
                'source_state_id' => 0,
                'target_state_id' => 0,
                'venipak_statuses' => ['picked up', 'at terminal', 'out for delivery'],
            ],
            [
                'enabled' => false,
                'source_state_id' => 0,
                'target_state_id' => 0,
                'venipak_statuses' => ['venipak pickup point'],
            ],
            [
                'enabled' => false,
                'source_state_id' => 0,
                'target_state_id' => 0,
                'venipak_statuses' => ['delivered'],
            ],
        ];

        $stored = Configuration::get('MOOVENIPAK_AUTO_SCENARIOS');
        $scenarios = $stored ? json_decode($stored, true) : [];
        if (!is_array($scenarios) || count($scenarios) !== 3) {
            $scenarios = $defaultScenarios;
        }

        $orderStates = OrderState::getOrderStates($this->context->language->id);
        $stateOptions = [];
        foreach ($orderStates as $st) {
            $stateOptions[] = [
                'id_option' => (int) $st['id_order_state'],
                'name' => sprintf('#%d - %s', (int) $st['id_order_state'], $st['name']),
            ];
        }

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Moovenipak automatic status'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable automation'),
                        'name' => 'MOOVENIPAK_AUTO_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'enabled_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'enabled_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Max orders per cron run'),
                        'name' => 'MOOVENIPAK_AUTO_MAX_PER_RUN',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('orders'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => 'submitMoovenipakstatus',
                ],
            ],
        ];

        for ($i = 1; $i <= 3; $i++) {
            $idx = $i - 1;
            $fieldsForm['form']['input'][] = [
                'type' => 'free',
                'name' => 'separator_'.$i,
                'label' => $this->l('Scenario').' '.$i,
            ];

            $fieldsForm['form']['input'][] = [
                'type' => 'switch',
                'label' => $this->l('Enabled'),
                'name' => 'scenario_'.$i.'_enabled',
                'is_bool' => true,
                'values' => [
                    [
                        'id' => 'scenario_'.$i.'_on',
                        'value' => 1,
                        'label' => $this->l('Yes'),
                    ],
                    [
                        'id' => 'scenario_'.$i.'_off',
                        'value' => 0,
                        'label' => $this->l('No'),
                    ],
                ],
            ];

            $fieldsForm['form']['input'][] = [
                'type' => 'select',
                'label' => $this->l('Source order state'),
                'name' => 'scenario_'.$i.'_source_state_id',
                'options' => [
                    'query' => $stateOptions,
                    'id' => 'id_option',
                    'name' => 'name',
                ],
            ];

            $fieldsForm['form']['input'][] = [
                'type' => 'select',
                'label' => $this->l('Target order state'),
                'name' => 'scenario_'.$i.'_target_state_id',
                'options' => [
                    'query' => $stateOptions,
                    'id' => 'id_option',
                    'name' => 'name',
                ],
            ];

            $fieldsForm['form']['input'][] = [
                'type' => 'textarea',
                'label' => $this->l('Venipak statuses (comma or new line separated, case-insensitive)'),
                'name' => 'scenario_'.$i.'_statuses',
                'cols' => 40,
                'rows' => 3,
            ];
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = Tools::safeOutput($_SERVER['REQUEST_URI']);
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->show_toolbar = false;
        $helper->table = $this->name;
        $helper->submit_action = 'submitMoovenipakstatus';

        $link = $this->context->link->getModuleLink('moovenipakstatus', 'cron', [
            'token' => Configuration::get('MOOVENIPAK_AUTO_TOKEN'),
        ]);

        $helper->fields_value = [
            'MOOVENIPAK_AUTO_ENABLED' => (int) Configuration::get('MOOVENIPAK_AUTO_ENABLED'),
            'MOOVENIPAK_AUTO_MAX_PER_RUN' => (int) Configuration::get('MOOVENIPAK_AUTO_MAX_PER_RUN'),
        ];

        for ($i = 1; $i <= 3; $i++) {
            $idx = $i - 1;
            $scenario = $scenarios[$idx];
            $helper->fields_value['scenario_'.$i.'_enabled'] = !empty($scenario['enabled']) ? 1 : 0;
            $helper->fields_value['scenario_'.$i.'_source_state_id'] = (int) ($scenario['source_state_id'] ?? 0);
            $helper->fields_value['scenario_'.$i.'_target_state_id'] = (int) ($scenario['target_state_id'] ?? 0);
            $helper->fields_value['scenario_'.$i.'_statuses'] = implode("\n", $scenario['venipak_statuses']);
        }

        $html = '<div class="alert alert-info">'
            .$this->l('Cron URL:').' <code>'.htmlspecialchars($link, ENT_QUOTES, 'UTF-8').'</code>'
            .'</div>';

        return $html.$helper->generateForm([$fieldsForm]);
    }
}
