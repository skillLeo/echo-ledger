// The v2 components draw with the app's one icon family (components/Icons.tsx and ControlIcons.tsx: outline glyphs on
// a 24-unit grid, one stroke, round ends, currentColor). This file only picks and names them; it draws nothing, so
// the one-icon rule keeps holding. The size comes from CSS (.el-icon), never from a number here.
import { ChatIcon, LucideArrowUpDownIcon, LucideCircleHelpIcon, LucideLogOutIcon, LucideSendIcon } from "../../components/Icons";

export {
  LucideSearchIcon as SearchIcon,
  LucideXIcon as CloseIcon,
  LucideCheckIcon as CheckIcon,
  LucideChevronRightIcon as ChevronEndIcon,
  LucideChevronLeftIcon as ChevronStartIcon,
  LucideCircleAlertIcon as ErrorIcon,
  LucideCircleCheckIcon as ReadyIcon,
  LucideTriangleAlertIcon as WarningIcon,
  LucideInfoIcon as InfoIcon,
  LucideMicIcon as MicIcon,
  LucidePhoneIcon as PhoneIcon,
  LucideCaptionsIcon as CaptionsIcon,
  LucideLanguagesIcon as LanguagesIcon,
  LucideBellIcon as BellIcon,
  LucideLockIcon as LockIcon,
  LucideHardDriveIcon as StorageIcon,
  LucideKeyboardIcon as KeyboardIcon,
  LucidePaletteIcon as AppearanceIcon,
  LucideShieldIcon as PrivacyIcon,
  LucideCircleHelpIcon as HelpIcon,
  LucideUserIcon as UserIcon,
  LucideCreditCardIcon as PlanIcon,
  LucideMonitorIcon as ScreenIcon,
  LucideAudioLinesIcon as WaveIcon,
  LucideFileTextIcon as TranscriptIcon,
  LucideUploadIcon as ShareIcon,
  LucideCircleDashedIcon as PendingIcon,
  LucideClockIcon as ClockIcon,
  LucideLogOutIcon as SignOutIcon,
  LucideCalendarIcon as CalendarIcon,
  LucideCopyIcon as CopyIcon,
  // Activity's server_problem (2.0.0-v).
  LucideServerIcon as ServerIcon,
  // The window's own buttons on Windows (UX-20): Restore is two windows, as the system draws it.
  LucideMinusIcon as WindowMinimizeIcon,
  LucideSquareIcon as WindowMaximizeIcon,
  LucideCopyIcon as WindowRestoreIcon,
  LucideSmartphoneIcon as DeviceIcon,
  LucideLaptopIcon as LaptopIcon,
  LucidePencilIcon as RenameIcon,
  LucideTagIcon as LabelIcon,
  LucidePinIcon as PinIcon,
  LucideFolderInputIcon as MoveIcon,
  LucideInfinityIcon as KeepIcon,
  LucideListChecksIcon as SelectIcon,
  LucideListFilterIcon as FilterIcon,
  LucideStarIcon as StarIcon,
  LucideMailIcon as MailIcon,
  LucideSendIcon as SendIcon,
  LucideArrowUpDownIcon as SortIcon,
  LucideArrowUpIcon as ArrowUpIcon,
  LucideRotateCcwIcon as BackIcon,
  LucideRotateCwIcon as ForwardIcon,
  LucideRadioIcon as RadioIcon,
  LucideLifeBuoyIcon as LifeBuoyIcon,
  LucideUserRoundPenIcon as UserRoundPenIcon,
  LucideFingerprintIcon as FingerprintIcon,
  LucideCommandIcon as CommandIcon,
} from "../../components/Icons";
export {
  SourceAppIcon,
  TrashIcon,
  FolderIcon,
  LibraryIcon,
  HomeIcon,
  SettingsIcon,
  RecordIcon,
  SparkleIcon,
  InboxIcon,
  ChatIcon as AskIcon,
  StoreIcon,
  VideoIcon,
  SkipIcon,
  RefreshIcon,
  DownloadIcon,
  GlobeIcon as WebIcon,
  ColumnsIcon,
  SidebarIcon,
  LinkIcon,
  PlusIcon,
  UsersIcon,
  RequestFilesIcon,
} from "../../components/Icons";
export { PlayIcon, PauseIcon, StopIcon, ChevronDownIcon, MoreIcon } from "../../components/ControlIcons";
// A row's "important" mark in its indicator slot (D12): a flag, drawn in the warning colour; a row never names the
// glyph, only ImportantIcon.
export { LucideFlagIcon as ImportantIcon } from "../../components/Icons";
// UX-07: the zoom's − beside PlusIcon (the avatar's row, the chip), and its glyph, a magnifier with +.
export { LucideMinusIcon as MinusIcon, LucideZoomInIcon as ZoomIcon } from "../../components/Icons";

/**
 * The glyphs that point a way or read as text, mirrored in a right-to-left window like every chevron and arrow (D-231;
 * decided for all three platforms): sort, sign out, send, Ask's speech bubble and help's "?" (Urdu writes ؟
 * the other way round). Icon() gives them .el-icon--directional wherever they are drawn. The media player's skip back
 * and forward never are: time runs left to right in every language.
 */
export const MIRRORED_IN_RTL: ReadonlySet<unknown> = new Set([LucideArrowUpDownIcon, LucideLogOutIcon, LucideSendIcon, ChatIcon, LucideCircleHelpIcon]);
