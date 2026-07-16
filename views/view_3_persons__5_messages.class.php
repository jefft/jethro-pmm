<?php
/**
 * Messages page — Send History with filters and per-recipient delivery status.
 *
 * Menu: Persons > SMS (requires PERM_VIEWSMS + SMS feature flag)
 */
class View_persons__messages extends View
{
    static function getMenuPermissionLevel()
    {
        return PERM_VIEWSMS;
    }

    static function getMenuRequiredFeature()
    {
        return 'SMS';
    }

    function getTitle()
    {
        return 'Messages';
    }

    function printView()
    {
        $GLOBALS['system']->includeDBClass('sms');
        $GLOBALS['system']->includeDBClass('smsdelivery');
        include_once 'urllinker.php';
        require_once JETHRO_ROOT . '/include/jethro_sms.php';
        $currentProviderKey = \Jethro\Sms\getCurrentSmsProviderKey();

        $hasRestrictions = !empty($GLOBALS['user_system']->getCurrentRestrictions());

        if ($hasRestrictions) {
            $sends = self::_getRestrictedSends();
            if (!$sends) {
                ?>
                <p><i>No SMS messages have been sent yet.</i></p>
                <?php
                return;
            }
            $smsIds = array_keys($sends);
            $deliveries = self::_getRestrictedDeliveries($smsIds);
        } else {
            $sends = $GLOBALS['system']->getDBObjectData('sms', [], 'AND', 'created DESC');
            if (!$sends) {
                ?>
                <p><i>No SMS messages have been sent yet.</i></p>
                <?php
                return;
            }
            $smsIds = array_keys($sends);
            $deliveries = $GLOBALS['system']->getDBObjectData('smsdelivery', ['sms_id' => $smsIds], 'AND', 'personid');
        }

        // Normalise delivery shapes and group by sms_id.
        $deliveriesBySmsId = [];
		foreach ($deliveries as $key => $d) {
			if (isset($d['id'])) {
				$d['_id'] = (int)$d['id'];
				$d['_visible'] = 1;
			} else {
				$d['_id'] = (int)$key;
				$d['_visible'] = 1;
			}
			$deliveriesBySmsId[(int)$d['sms_id']][] = $d;
		}

        ?>
            <div class="sms-history-layout">
                <div class="panel-sidebar" id="sms-history-filters">
            <h4 class="hidden-phone"><?php echo _('Filters'); ?></h4>
            <fieldset class="hidden-phone">
                <legend><?php echo _('Sender:'); ?></legend>
                <label class="checkbox sms-filter-noindent">
                    <input type="text" id="sms-filter-sender" data-bind="filterSender" style="width:130px" placeholder="Name..." data-on:input__debounce.150ms="updateSmsCostSum()" />
                </label>
                <legend><?php echo _('Recipient:'); ?></legend>
                <label class="checkbox sms-filter-noindent">
                    <input type="text" id="sms-filter-recipient" data-bind="filterRecipient" style="width:130px" placeholder="Name..." data-on:input__debounce.150ms="updateSmsCostSum()" />
                </label>
                <label class="checkbox">
                    <input type="checkbox" id="sms-filter-single-only" data-bind="filterSingleOnly" data-on:input="updateSmsCostSum()" /> <?php echo _('Single recipients only'); ?>
                </label>
                <legend><?php echo _('Body:'); ?></legend>
                <label class="checkbox sms-filter-noindent">
                    <input type="text" id="sms-filter-body" data-bind="filterBody" style="width:130px" placeholder="Text..." data-on:input__debounce.150ms="updateSmsCostSum()" />
                </label>
                <legend><?php echo _('Date:'); ?></legend>
                <label class="checkbox sms-filter-noindent sms-filter-dates">
                    <span class="sms-filter-date-row"><?php echo _('From'); ?> <input type="date" id="sms-filter-date-from" data-bind="filterDateFrom" style="width:110px" data-on:input="updateSmsCostSum()" /></span>
                    <span class="sms-filter-date-row"><?php echo _('To'); ?> <input type="date" id="sms-filter-date-to" data-bind="filterDateTo" style="width:110px" data-on:input="updateSmsCostSum()" /></span>
                </label>
                <button type="button" class="btn btn-mini" id="sms-filter-clear" style="margin-top:10px" data-on:click="$filterSender = ''; $filterRecipient = ''; $filterBody = ''; $filterDateFrom = ''; $filterDateTo = ''; $filterSingleOnly = false; updateSmsCostSum()"><?php echo _('Clear filters'); ?></button>
            </fieldset>
            <h4 class="hidden-phone">Show also:</h4>
            <fieldset class="hidden-phone">
                <label class="checkbox">
                    <input type="checkbox" id="sms-filter-show-cost" data-bind="showCost" /> <?php echo _('Cost'); ?>
                </label>
            </fieldset>
        </div>
        <table class="table table-sortable table-striped table-hover sms-history-table">
            <thead>
                <tr>
                    <th data-sort="string">Created</th>
                    <th data-sort="string-ins">Sender</th>
                    <th class="sms-history-recipients no-sort">Recipients</th>
                    <th data-sort="string">Body</th>
                    <th data-show="$showCost" style="display:none" data-sort="float">Cost<br><span id="sms-cost-total">$0.00</span></th>
                    <th class="">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($sends as $smsId => $send) {
                $sendDeliveries = $deliveriesBySmsId[$smsId] ?? [];
                $hasScheduled = (int)($send['scheduled_count'] ?? 0) > 0;
                $uniqueBodies = array_unique(array_map(fn(array $d): string => (string)($d['body'] ?? ''), $sendDeliveries));
                $hasTokens = count($uniqueBodies) > 1;
                $senderName = (!empty($send['sender_fn']) ? trim($send['sender_fn'] . ' ' . $send['sender_ln']) : 'system');

                $visibleDeliveries = [];
                $hiddenCount = 0;
                foreach ($sendDeliveries as $d) {
                    if (!empty($d['_visible'])) {
                        $visibleDeliveries[] = $d;
                    } else {
                        $hiddenCount++;
                    }
                }

                $recipientNames = [];
                foreach ($visibleDeliveries as $d) {
                    $recipientNames[] = $d['recipient_name'] ?? '';
                }

                $cost = (float)($send['cost'] ?? 0);
                ?>
                <tr data-sender="<?php echo ents($senderName); ?>"
                    data-recipients="<?php echo ents(implode(' ', $recipientNames)); ?>"
                    data-multi="<?php echo count($sendDeliveries) > 1 ? '1' : '0'; ?>"
                    data-body="<?php echo ents($send['body'] ?? ''); ?>"
                    data-created="<?php echo ents($send['created']); ?>"
                    data-cost="<?php echo $cost; ?>"
                    data-show="(!$filterSender || el.dataset.sender.toLowerCase().includes($filterSender.trim().toLowerCase()))
                      && (!$filterRecipient || el.dataset.recipients.toLowerCase().includes($filterRecipient.trim().toLowerCase()))
                      && (!$filterBody || el.dataset.body.toLowerCase().includes($filterBody.trim().toLowerCase()))
                      && (!$filterDateFrom || el.dataset.created.slice(0, 10) >= $filterDateFrom)
                      && (!$filterDateTo || el.dataset.created.slice(0, 10) <= $filterDateTo)
                      && (!$filterSingleOnly || el.dataset.multi === '0')"
                    id="message_<?php echo (int)$smsId; ?>">
                    <td class="" data-sort-value="<?php echo ents($send['created']); ?>">
                        <?php echo format_datetime($send['created']); ?>
                    </td>
                    <td>
                        <?php
                        if (!empty($send['sender_fn'])) {
                            echo ents(trim($send['sender_fn'] . ' ' . $send['sender_ln']));
                        } else {
                            echo '<i>system</i>';
                        }
                        ?>
                    </td>
                    <td class="sms-history-recipients">
                        <div class="sms-history-recipients-scroll">
                        <?php
                        foreach ($visibleDeliveries as $d) {
                            $name = $d['recipient_name'] ?: ('Person #' . ((int)$d['personid']));
                            $status = $d['status'] ?? 'unknown';
                            $did = (int)($d['_id'] ?? 0);
                            $pid = (int)$d['personid'];
                            $body = (string)($d['body'] ?? '');
                            $dprovider = $d['provider'] ?? null;
                            if ($pid && $did) {
                            echo \Jethro\Sms\renderSmsDeliveryStatusIcon(
                                status: $status,
                                scheduledAt: $send['scheduled_send_at'] ?? null,
                                deliveryId: $did ?: null,
                                providerKey: $dprovider,
                                currentProviderKey: $currentProviderKey,
                            );
                                ?>
                                <a href="/?view=persons&amp;personid=<?php echo $pid; ?>#message_<?php echo (int)$smsId; ?>"
                                <?php if ($hasTokens && $body !== ''): ?> title="<?php echo ents($body); ?>"<?php endif; ?>>
                                    <?php echo ents($name); ?>
                                </a>
                                <?php
                            } else {
                                echo ents($name);
                            }
                            ?><br />
                            <?php
                        }
                        if ($hiddenCount > 0) {
                            ?>
                            <span class="text-muted"><i>Visibility of <?php echo $hiddenCount; ?> other recipient<?php echo $hiddenCount > 1 ? 's' : ''; ?> limited by permissions</i></span>
                            <?php
                        }
                        ?>
                        </div>
                    </td>
                    <td>
                        <?php
                        $body = (string)($send['body'] ?? '');
                        echo linkUrlsInTrustedHtml(nl2br(ents($body)));
                        ?>
                    </td>
                    <td class="sms-history-cost" data-show="$showCost" style="display:none" data-sort-value="<?php echo number_format($cost, 2, '.', ''); ?>">
                        <?php echo '$' . number_format($cost, 2); ?>
                    </td>
                    <td class="sms-history-actions action-cell">
                        <?php if ($hasScheduled): ?>
                            <span id="sms-cancel-<?php echo (int)$smsId; ?>">
                            <a href="#"
                               data-on:click="@post('?call=sms_cancel&sms_id=<?php echo (int)$smsId; ?>')">
                                <?php echo count($sendDeliveries) > 1 ? 'Cancel all' : 'Cancel'; ?>
                            </a>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
        </div><!-- /.sms-history-layout -->
        <?php
    }

    /**
     * Query sms rows with restriction-aware delivery aggregates.
     */
    private static function _getRestrictedSends(): array
    {
        $db = $GLOBALS['db'];
        $rows = $db->queryAll(
            'SELECT sms.*,
                COALESCE(sender_p.first_name, \'\') AS sender_fn,
                COALESCE(sender_p.last_name, \'\') AS sender_ln,
                COUNT(rd.id) AS recipient_count,
                SUM(rd.status IN (\'queued\',\'sent\',\'delivered\',\'test-message\')) AS delivered_count,
                SUM(rd.status IN (\'failed\',\'cancelled\')) AS failed_count,
                SUM(rd.status = \'scheduled\') AS scheduled_count
            FROM sms
            LEFT JOIN _person sender_p ON sender_p.id = sms.sender
            JOIN smsdelivery rd ON rd.sms_id = sms.id
            LEFT JOIN person rp ON rp.id = rd.personid
            WHERE rp.id IS NOT NULL OR rd.personid IS NULL
            GROUP BY sms.id
            ORDER BY sms.created DESC'
        );
        if ($rows === false) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['id']] = $row;
        }
        return $result;
    }

    /**
     * Query smsdelivery rows with restriction-aware recipient names.
     */
    private static function _getRestrictedDeliveries(array $smsIds): array
    {
        if ($smsIds === []) {
            return [];
        }
        $db = $GLOBALS['db'];
        $placeholders = implode(',', array_fill(0, count($smsIds), '?'));
        $rows = $db->queryAll(
            "SELECT sd.id, sd.sms_id, sd.personid, sd.body, sd.status, sd.provider,
                CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) AS recipient_name,
                (p.id IS NOT NULL OR sd.personid IS NULL) AS _visible
            FROM smsdelivery sd
            LEFT JOIN person p ON p.id = sd.personid
            WHERE sd.sms_id IN ({$placeholders})
            ORDER BY sd.personid",
            array_map('intval', $smsIds)
        );
        return $rows !== false ? $rows : [];
    }
}
