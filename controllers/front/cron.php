<?php

class MoovenipakstatusCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $token = Tools::getValue('token');
        $expected = Configuration::get('MOOVENIPAK_AUTO_TOKEN');
        if (!$expected || $token !== $expected) {
            header('HTTP/1.1 403 Forbidden');
            die('Forbidden');
        }

        $enabled = (bool) Configuration::get('MOOVENIPAK_AUTO_ENABLED');
        if (!$enabled) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Module disabled',
            ]));
        }

        require_once _PS_MODULE_DIR_.'mijoravenipak/classes/MjvpDb.php';
        // Ensure main module class is available before MjvpApi uses MjvpBase -> new MijoraVenipak()
        require_once _PS_MODULE_DIR_.'mijoravenipak/mijoravenipak.php';
        require_once _PS_MODULE_DIR_.'mijoravenipak/classes/MjvpApi.php';
        require_once _PS_MODULE_DIR_.'moovenipakstatus/classes/MoovEnipakStatusService.php';

        $limit = (int) Configuration::get('MOOVENIPAK_AUTO_MAX_PER_RUN');
        if ($limit <= 0) {
            $limit = 100;
        }

        $service = new MoovEnipakStatusService();
        $refreshed = $service->refreshVenipakTracking($limit);
        $updated = $service->applyScenarios($limit);

        $this->ajaxDie(json_encode([
            'success' => true,
            'refreshed' => $refreshed,
            'updated' => $updated,
        ]));
    }
}
