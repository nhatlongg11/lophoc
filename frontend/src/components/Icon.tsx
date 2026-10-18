import {
  HomeIcon, UserGroupIcon, ClipboardDocumentCheckIcon, PuzzlePieceIcon,
  CalendarDaysIcon, CheckBadgeIcon, ArrowTrendingUpIcon, ChatBubbleLeftRightIcon,
  ChartBarIcon, QuestionMarkCircleIcon, BuildingOffice2Icon, BellIcon,
  DocumentTextIcon, Cog6ToothIcon, UserCircleIcon, Bars3Icon, XMarkIcon,
  ChevronLeftIcon, ChevronRightIcon, PlusIcon, ArrowDownTrayIcon, ArrowUpTrayIcon,
  CheckIcon, ExclamationCircleIcon, SparklesIcon, BoltIcon, ArrowsRightLeftIcon,
  LanguageIcon, PencilSquareIcon, MusicalNoteIcon, TrophyIcon, ClockIcon,
  ArrowRightIcon, ArrowLeftIcon, LockClosedIcon, Squares2X2Icon, StarIcon, FireIcon, ArrowPathIcon, SpeakerWaveIcon, SpeakerXMarkIcon, PlayIcon, ArchiveBoxIcon, BookOpenIcon, AcademicCapIcon,
} from '@heroicons/react/24/outline'

const icons = {
  dashboard: HomeIcon, students: UserGroupIcon, assignments: ClipboardDocumentCheckIcon,
  games: PuzzlePieceIcon, schedule: CalendarDaysIcon, attendance: CheckBadgeIcon,
  progress: ArrowTrendingUpIcon, communication: ChatBubbleLeftRightIcon, reports: ChartBarIcon,
  banks: QuestionMarkCircleIcon, organization: BuildingOffice2Icon, notifications: BellIcon,
  resources: DocumentTextIcon, admin: Cog6ToothIcon, profile: UserCircleIcon, menu: Bars3Icon,
  close: XMarkIcon, previous: ChevronLeftIcon, next: ChevronRightIcon, plus: PlusIcon,
  download: ArrowDownTrayIcon, upload: ArrowUpTrayIcon, check: CheckIcon,
  error: ExclamationCircleIcon, sparkle: SparklesIcon, quiz: BoltIcon,
  matching: ArrowsRightLeftIcon, word_builder: LanguageIcon, fill_blank: PencilSquareIcon,
  listening: MusicalNoteIcon, challenge: TrophyIcon, clock: ClockIcon,
  arrow_right: ArrowRightIcon, arrow_left: ArrowLeftIcon, lock: LockClosedIcon, memory: Squares2X2Icon, star: StarIcon, streak: FireIcon, refresh: ArrowPathIcon, sound_on: SpeakerWaveIcon, sound_off: SpeakerXMarkIcon, play: PlayIcon, archive: ArchiveBoxIcon, book: BookOpenIcon, academic: AcademicCapIcon,
}

export type IconName = keyof typeof icons

/** Decorative icons inherit text color; the surrounding control supplies its accessible name. */
export function Icon({ name, className = '' }: { name: IconName; className?: string }) {
  const Glyph = icons[name]
  return <Glyph className={'ui-icon ' + className} aria-hidden="true" focusable="false" strokeWidth={1.8}/>
}
