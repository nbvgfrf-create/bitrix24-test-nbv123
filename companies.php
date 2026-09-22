<?php

function importCompanies(Bitrix $b, array &$companies, array $contacts, array $distributors, array $setup, int &$done, float $started)
{
    $contactMap = [];
    foreach ($contacts as $contact) {
        if (($contact['status'] ?? '') === 'done' && !empty($contact['bitrix_id'])) {
            $contactMap[$contact['key']] = (int)$contact['bitrix_id'];
        }
    }

    $distributorMap = [];
    foreach ($distributors as $distributor) {
        if (($distributor['status'] ?? '') === 'done' && !empty($distributor['bitrix_id'])) {
            $distributorMap[$distributor['key']] = (int)$distributor['bitrix_id'];
        }
    }

    $fields = $b->list('crm.company.userfield.list', ['filter' => ['LANG' => 'ru']]);
    $uf = [];
    foreach ($fields as $field) {
        $uf[$field['FIELD_NAME']] = $field;
    }

    $softwareIds = [];
    if (isset($uf['UF_CRM_COMPETITOR_SOFTWARE'])) {
        foreach (($uf['UF_CRM_COMPETITOR_SOFTWARE']['LIST'] ?? []) as $option) {
            $softwareIds[$option['VALUE']] = $option['ID'];
        }
    }

    $industryIds = [];
    foreach ($b->list('crm.status.list', ['filter' => ['ENTITY_ID' => 'INDUSTRY']]) as $item) {
        $industryIds[$item['NAME']] = $item['STATUS_ID'];
    }

    $typeIds = $setup['company_types'] ?? [];

    foreach ($companies as &$company) {
        if (($company['status'] ?? '') === 'done') {
            continue;
        }
        if (microtime(true) - $started >= WORK_TIME) {
            break;
        }

        try {
            $contactIds = [];
            foreach ($company['contact_keys'] as $key) {
                if (isset($contactMap[$key])) {
                    $contactIds[] = $contactMap[$key];
                }
            }

            $distributorIds = [];
            foreach ($company['distributors'] as $key) {
                if (isset($distributorMap[$key])) {
                    $distributorIds[] = $distributorMap[$key];
                }
            }

            $companyFields = [
                'TITLE' => $company['name'],
                'ASSIGNED_BY_ID' => (int)$setup['responsible_id'],
                'COMPANY_TYPE' => $typeIds[$company['type']] ?? null,
                'INDUSTRY' => $industryIds[$company['industry']] ?? null,
                'UF_CRM_IMPORT_COUNTRY' => 'Россия',
                'UF_CRM_OLD_RESPONSIBLE' => $company['old_responsible'],
            ];

            if (!$companyFields['COMPANY_TYPE']) {
                unset($companyFields['COMPANY_TYPE']);
            }
            if (!$companyFields['INDUSTRY']) {
                unset($companyFields['INDUSTRY']);
            }

            if (isset($uf['UF_CRM_COMPETITOR_SOFTWARE'])) {
                $values = [];
                foreach ($company['competitor_software'] as $software) {
                    if (isset($softwareIds[$software])) {
                        $values[] = $softwareIds[$software];
                    }
                }
                $companyFields['UF_CRM_COMPETITOR_SOFTWARE'] = $values;
            }

            if ($company['license_expiration_date'] !== '') {
                $companyFields['UF_CRM_LICENSE_EXPIRATION'] = $company['license_expiration_date'];
            }

            if (isset($uf['UF_CRM_DISTRIBUTOR'])) {
                $companyFields['UF_CRM_DISTRIBUTOR'] = $distributorIds;
            }

            if ($contactIds) {
                $companyFields['CONTACT_ID'] = $contactIds[0];
                if (count($contactIds) > 1) {
                    $companyFields['CONTACT_IDS'] = $contactIds;
                }
            }

            $companyId = $b->call('crm.company.add', ['fields' => $companyFields]);
            $company['bitrix_id'] = (int)$companyId;

            // Если контактов несколько, привязываем остальные отдельно.
            foreach ($contactIds as $contactId) {
                $b->call('crm.contact.company.add', [
                    'id' => $contactId,
                    'fields' => ['COMPANY_ID' => $companyId],
                ]);
            }

            // Реквизиты создаём только если есть адрес.
            if ($company['address'] !== '') {
                $presets = $b->list('crm.requisite.preset.list', ['filter' => ['ENTITY_TYPE_ID' => 4]]);
                $presetId = null;
                foreach ($presets as $preset) {
                    if ((string)($preset['COUNTRY_ID'] ?? '') === '1') {
                        $presetId = (int)$preset['ID'];
                        break;
                    }
                }

                if ($presetId) {
                    $requisiteId = $b->call('crm.requisite.add', ['fields' => [
                        'ENTITY_TYPE_ID' => 4,
                        'ENTITY_ID' => $companyId,
                        'PRESET_ID' => $presetId,
                        'NAME' => $company['name'],
                        'RQ_COMPANY_NAME' => $company['name'],
                    ]]);

                    $addressType = 1;
                    $types = $b->call('crm.enum.addresstype');
                    foreach (($types ?? []) as $type) {
                        if (strcasecmp($type['NAME'] ?? '', $company['address_type']) === 0) {
                            $addressType = (int)$type['ID'];
                            break;
                        }
                    }

                    $b->call('crm.address.add', ['fields' => [
                        'TYPE_ID' => $addressType,
                        'ENTITY_TYPE_ID' => 8,
                        'ENTITY_ID' => $requisiteId,
                        'ADDRESS_1' => $company['address'],
                        'COUNTRY' => 'Россия',
                    ]]);
                }
            }

            $company['status'] = 'done';
            $done++;
            logMessage("Компания создана: {$company['name']} => {$companyId}");
        } catch (Throwable $e) {
            $company['status'] = 'error';
            $company['error'] = $e->getMessage();
            logMessage("Ошибка компании {$company['name']}: {$e->getMessage()}");
        }
    }
    unset($company);
}
