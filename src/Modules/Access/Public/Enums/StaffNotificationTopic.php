<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * What a staff member can be notified about (handoff §7.6), each by email and in the panel. Ops
 * sends the notifications later and reads the preferences through AccessApi.
 */
enum StaffNotificationTopic: string
{
    case NewOrders = 'NEW_ORDERS';
    case CompanyApplications = 'COMPANY_APPLICATIONS';
    case LowStock = 'LOW_STOCK';
    case CampaignExpiry = 'CAMPAIGN_EXPIRY';
}
