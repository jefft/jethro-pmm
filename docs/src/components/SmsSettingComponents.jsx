/**
 * SMS-specific setting group components.
 *
 * Re-exports the generic setting components from {@link SettingComponents}
 * and adds convenience wrappers for each SMS setting group defined in
 * jethrosettings.json.
 *
 * MDX pages that only need generic setting rendering (glossary tables,
 * non-SMS admin pages) should import from `./SettingComponents` directly.
 */
import { getData } from '../settingsData';
import { group } from './SettingComponents';

// Re-export everything from the generic module
export {
  SETTINGS,
  SingleSetting,
  MultiSettings,
  SettingsGlossaryTable,
  setting_groups,
  SettingRow,
} from './SettingComponents';

const GROUPS = getData().groups;

export const SettingSmsSenderGroup = group(GROUPS.sender?.settings);
export const SettingSmsMessageBodyGroup = group(GROUPS.messageBody?.settings);
export const SettingSmsPostSendBehaviourGroup = group(GROUPS.postSendBehaviour?.settings);
export const SettingSmsBalanceGroup = group(GROUPS.balance?.settings);
export const SettingSmsDebuggingGroup = group(GROUPS.debugging?.settings);
export const SettingSmsProviderGroup = group(GROUPS.providerSelection?.settings);
export const SettingSms5centsmsGroup = group(GROUPS.fiveCentSms?.settings);
export const SettingSmsCellcastGroup = group(GROUPS.cellcast?.settings);
