<?php
try {
    \Bitrix\Main\Loader::requireModule('crm');

    $instance = \Bitrix\Crm\Service\Container::getInstance();
    $documentService = $this->workflow->GetService('DocumentService');
    $document = $documentService->getDocument($this->getDocumentId());
    $dealId = $document['ID'];

    $leadIds = [];

    $dealContactIds = getContactsOfDeal($dealId, $instance);
    $phones = [];
    foreach ($dealContactIds as $contactId) {
        $leadIds = array_merge(getLeadsOfContact($contactId, $instance), $leadIds);
        $contactPhones = getPhonesOfContact($contactId, $instance);
        $phones = array_merge($contactPhones, $phones);
    }
    $phones = array_unique($phones);

    foreach ($phones as $phone) {
        $leadIds = array_merge(findLeadsByPhone($phone), $leadIds);
    }
    $leadIds = checkLeads(
        array_unique($leadIds, SORT_NUMERIC)
    );
    $this->SetVariable('LeadIds', $leadIds);
} catch (\Throwable $e) {
    $this->WriteToTrackingService(
        sprintf(
            "Error %s on line %s in file %s",
            $e->getMessage(),
            $e->getLine(),
            $e->getFile()
        ),
        0,
        CBPTrackingType::FaultActivity
    );
}

function getPhonesOfContact($contactId, $instance): array
{
    $phones = [];
    $factory = $instance->getFactory(\CCrmOwnerType::Contact);
    $contact = $factory->getItem($contactId);
    if (!$contact) {
        return [];
    }

    $res = \CCrmFieldMulti::GetListEx(
        [],
        [
            'ENTITY_ID'  => \CCrmOwnerType::Contact,
            'ELEMENT_ID' => $contactId,
            'TYPE_ID'    => \CCrmFieldMulti::PHONE,
        ]
    );

    while ($row = $res->Fetch()) {
        $phones[] = $row['VALUE'];
    }
    return array_unique($phones);
}

function getContactsOfDeal($dealId, $instance): array
{
    $factory = $instance->getFactory(\CCrmOwnerType::Deal);
    $deal = $factory->getItem($dealId);
    if (!$deal) {
        return [];
    }
    $contacts = $deal->getContactBindings();
    $ids = [];
    foreach ($contacts as $contact) {
        $ids[] = $contact['CONTACT_ID'];
    }
    return array_unique($ids);
}

function getLeadsOfContact($contactId, $instance): array
{
    $relationManager = $instance->getRelationManager();
    $itemIdentifier = new \Bitrix\Crm\ItemIdentifier(\CCrmOwnerType::Contact, $contactId);
    $bindedElements =  $relationManager->getElements($itemIdentifier);
    $ids = [];
    foreach ($bindedElements as $bindedElement) {
        $bindedId = $bindedElement->getEntityId();
        if ($bindedElement->getEntityTypeId() == \CCrmOwnerType::Lead) {
            $ids[] = $bindedId;
        }
    }
    return $ids;
}

function findLeadsByPhone(string $phone): array
{
    $phone = preg_replace('/[^\d+]/', '', $phone);
    $leadIds = [];
    $adapter = \Bitrix\Crm\EntityAdapterFactory::create(
        [
            'FM' => [
                'PHONE' => [
                    ['VALUE' => $phone]
                ]
            ]
        ],
        \CCrmOwnerType::Lead
    );

    $dups = (new \Bitrix\Crm\Integrity\ContactDuplicateChecker())
        ->findDuplicates(
            $adapter,
            new \Bitrix\Crm\Integrity\DuplicateSearchParams([
                'FM.PHONE'
            ])
        );

    foreach ($dups as &$dup) {
        if (!($dup instanceof \Bitrix\Crm\Integrity\Duplicate)) {
            continue;
        }

        $entities = $dup->getEntities();
        if (!(is_array($entities) && !empty($entities))) {
            continue;
        }

        foreach ($entities as &$entity) {
            if (!($entity instanceof \Bitrix\Crm\Integrity\DuplicateEntity)) {
                continue;
            }

            if ($entity->getEntityTypeID() != \CCrmOwnerType::Lead) {
                continue;
            }

            $id = $entity->getEntityID();
            $leadIds[] = $id;
        }
    }
    return array_unique($leadIds);
}

function checkLeads($leadIds): array
{
    if (empty($leadIds)) {
        return [];
    }

    $rows = \Bitrix\Crm\LeadTable::getList([
        'filter' => ['@ID' => $leadIds],
        'select' => ['ID', 'STATUS_ID']
    ])->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        if (!in_array($r['STATUS_ID'], ['CONVERTED', 'JUNK'])) {
            $result[] = (int)$r['ID'];
        }
    }
    return $result;
}
