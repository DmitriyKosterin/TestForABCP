<?php

namespace NW\WebService\References\Operations\Notification;
use Error;
use NW\WebService\References\Operations\Notification\Seller;
use NW\WebService\References\Operations\Notification\Contractor;
use NW\WebService\References\Operations\Notification\Employee;
use NW\WebService\References\Operations\Notification\Status;

/**
 * Этот код представляет собой реализацию операции веб-сервиса в рамках определенного приложения. В частности, это операция по возврату товаров (TsReturnOperation) с отправкой уведомлений различным пользователям.
 *
 * Назначение основных частей кода:
 *
 * Константы: Определены константы TYPE_NEW и TYPE_CHANGE для удобного использования типов уведомлений.
 *
 * Метод doOperation: Этот метод выполняет основную логику операции возврата товаров. Включает в себя обработку входных данных, проверки наличия необходимых данных, создание сообщений для уведомлений и их отправку.
 *
 * Проверки и исключения: Код содержит проверки и выбрасывание исключений в случае ошибок, например, если не удалось найти продавца (Seller not found!), клиента (Client not found!), создателя (Creator not found!), эксперта (Expert not found!), или если не удалось определить различия в статусе товара.
 *
 * Создание уведомлений: Используются различные методы для отправки уведомлений, включая сообщения по электронной почте (MessagesClient::sendMessage) и SMS-уведомления (NotificationManager::send).
 *
 * Качество кода оставляет желать лучшего, он даже не компилировался, тип возвращаемого значения doOperation() не был задан, $error не был задан, не было ссылок на классы из файла others.php. Из некритичного, фраза client not found! была с маленькой буквы
 */

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $resellerId = $data['resellerId'];
        $notificationType = (int)$data['notificationType'];
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        if (empty((int)$resellerId)) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            return $result;
        }

        if (empty((int)$notificationType)) {
            throw new \Exception('Empty notificationType', 400);
        }

        $reseller = Seller::getById((int)$resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }

        $client = Contractor::getById((int)$data['clientId']);
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('Client not found!', 400);
        }

        $cFullName = $client->getFullName();
        if (empty($cFullName)) {
            $cFullName = $client->name;
        }

        $cr = Employee::getById((int)$data['creatorId']);
        if ($cr === null) {
            throw new \Exception('Creator not found!', 400);
        }

        $et = Employee::getById((int)$data['expertId']);
        if ($et === null) {
            throw new \Exception('Expert not found!', 400);
        }

        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO'   => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }

        $templateData = [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$data['complaintNumber'],
            'CREATOR_ID'         => (int)$data['creatorId'],
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => (int)$data['expertId'],
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => (int)$data['clientId'],
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$data['agreementNumber'],
            'DATE'               => (string)$data['date'],
            'DIFFERENCES'        => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }

        $emailFrom = getResellerEmailFrom($resellerId);
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $email,
                        'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $client->email,
                        'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            $error = new Error();
            if (!empty($client->mobile)) {
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (isset($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
