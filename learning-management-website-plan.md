# Learning Management Website Plan

> **Language requirement:** This planning document is written in English. However, the actual website must use **Vietnamese** for all user-facing content, including navigation, buttons, labels, messages, validation errors, notifications, reports, and learning materials.

## 1. Project Overview

The website will be a **classroom management and interactive learning platform** that helps teachers assign work, manage schedules, record attendance, and monitor each student's progress.

The product should prioritize:

- A simple workflow that saves teachers time.
- A student experience that works well on computers and mobile devices.
- Centralized and easy-to-understand learning information.
- An architecture that can later support parents, advanced reports, and additional educational games.
- A fully Vietnamese user interface suitable for Vietnamese teachers and students.

## 2. User Roles

### 2.1. Administrator

- Manage user accounts.
- Manage classes, subjects, school years, and semesters.
- Assign roles and permissions to teachers, students, and parents.
- Configure system-wide settings.
- Review activity logs and system status.

### 2.2. Teacher

- Manage assigned classes.
- Assign work as files or educational games.
- Manage class schedules.
- Record student attendance.
- Grade submissions and provide feedback.
- Monitor the progress of individual students and entire classes.

### 2.3. Student

- View the class schedule and upcoming tasks.
- Receive and complete assignments.
- Upload files or enter answers directly.
- Participate in educational games.
- View scores, feedback, and personal progress.

### 2.4. Parent

The parent role can be introduced in a later phase:

- View the child's schedule.
- Monitor attendance.
- Review learning progress and results.
- Receive important notifications from teachers or the school.

## 3. Core Features

### 3.1. Assignment Management

#### Teacher features

- Create assignments for one or multiple classes.
- Enter a title, instructions, grading criteria, and maximum score.
- Set a start date and submission deadline.
- Attach PDF, Word, PowerPoint, image, video, or link resources.
- Allow students to upload files or enter answers directly.
- Grade submissions, leave comments, and request resubmission.
- View lists of submitted, missing, and late assignments.
- Duplicate previous assignments for another class.

#### Student features

- Receive a notification when a new assignment is published.
- Read instructions and download attached materials.
- Work on and save a draft.
- Submit or update work before the applicable deadline.
- View grades and teacher feedback.

Assignment status flow:

```text
Chưa làm → Đang làm → Đã nộp → Đã chấm
Not started → In progress → Submitted → Graded
```

The production interface should display only the Vietnamese labels. Late and resubmission-required states should be shown separately.

### 3.2. Educational Mini-Games

The first release should support reusable game types that are straightforward to create:

- Multiple-choice quiz.
- True or false.
- Matching pairs.
- Answer ordering.
- Fill in the blanks.
- Timed challenge.

Each attempt should record:

- Score achieved.
- Number of correct and incorrect answers.
- Number of attempts.
- Completion time.
- Questions or knowledge areas the student commonly answers incorrectly.

Teachers should be able to use a game either as optional practice or as a graded activity.

### 3.3. Timetable

- Display schedules by day, week, and month.
- Provide separate views for classes, teachers, and students.
- Show the subject, lesson period, classroom, and assigned teacher.
- Support rescheduled lessons, make-up classes, and cancellations.
- Display assignments and deadlines on the calendar.
- Remind students about upcoming lessons and deadlines.
- Detect scheduling conflicts involving teachers, classes, or rooms.

### 3.4. Student Progress Tracking

The teacher dashboard should display:

- Assignment completion rate.
- Average scores by subject and over time.
- Number of missing and late assignments.
- Educational game results.
- Attendance rate.
- Topics or question groups that the student frequently gets wrong.
- Students who may need additional support.

Suggested visual status indicators:

- **Green:** progressing well.
- **Yellow:** requires attention.
- **Red:** behind schedule or frequently absent.

Alert thresholds should be configurable. The system should support teachers' decisions rather than make definitive judgments about students automatically.

### 3.5. Student Attendance

- Record attendance by session or lesson period.
- Support the statuses present, late, excused absence, and unexcused absence.
- Add notes and absence reasons.
- Allow corrections while preserving a change history.
- Provide weekly, monthly, semester, and school-year statistics.
- Export attendance reports.
- Add QR-based attendance in a later phase if needed.

For faster data entry, all students can initially be marked as **Có mặt** (Present). The teacher then changes only the students who are late or absent.

### 3.6. Notifications

- Notify students when a new assignment is published.
- Remind students about approaching deadlines.
- Notify students when an assignment has been graded.
- Announce timetable changes.
- Report absences or late arrivals.
- Allow notifications to be marked as read or unread.

The MVP should prioritize in-app notifications. Email, Zalo, or push notifications can be added later.

## 4. Information Architecture

The application can use the following main structure. The Vietnamese labels shown below are the labels that should appear in the actual interface.

```text
Tổng quan
├── Việc cần làm hôm nay
├── Buổi học sắp tới
├── Bài tập sắp hết hạn
└── Học sinh cần chú ý

Lớp học
├── Danh sách học sinh
├── Bài tập
├── Trò chơi
├── Điểm danh
└── Báo cáo tiến độ

Thời khóa biểu
Thông báo
Kho tài liệu
Hồ sơ cá nhân
Quản trị hệ thống
```

The student interface should focus on answering three questions:

1. What am I studying today?
2. Which assignments do I need to complete?
3. How am I currently progressing?

In the actual interface, these prompts should be written in Vietnamese:

1. Hôm nay học gì?
2. Cần làm bài nào?
3. Kết quả học tập hiện tại ra sao?

## 5. Essential User Flows

### 5.1. Assignment Flow

```text
Teacher creates an assignment
→ Selects one or more classes
→ Attaches files or selects a game
→ Sets the deadline
→ Students receive a notification
→ Students complete and submit the work
→ Teacher grades and provides feedback
```

### 5.2. Attendance Flow

```text
Teacher selects a class and lesson
→ System loads the student list
→ All students are marked present by default
→ Teacher updates exceptions
→ Teacher confirms attendance
→ System updates attendance reports
```

### 5.3. Progress Monitoring Flow

```text
Assignment data + game results + attendance data
→ Aggregated for each student
→ At-risk cases are highlighted
→ Teacher reviews the details
→ Teacher adds notes or creates a support plan
```

## 6. MVP Scope

The first release should include:

1. Authentication and role-based access control.
2. Class, subject, teacher, and student management.
3. File-based assignment creation, submission, and grading.
4. One multiple-choice quiz game.
5. Weekly timetable.
6. Manual attendance recording.
7. Basic student progress reports.
8. In-app notifications.
9. Vietnamese localization for every user-facing screen.

The following features should not be included in the initial MVP:

- Built-in video calls.
- Real-time chat.
- Automatic AI grading for written answers.
- A complex game library.
- Facial-recognition attendance.
- QR attendance before the basic attendance workflow has been validated.

## 7. Suggested Development Roadmap

| Phase | Estimated duration | Deliverable |
|---|---:|---|
| Requirements analysis and UX design | 1–2 weeks | User flows, wireframes, and prototype |
| Accounts, classes, and timetable | 2 weeks | Core management foundation |
| Assignments, file submission, and grading | 2–3 weeks | Complete assignment workflow |
| Attendance and progress reporting | 2 weeks | Teacher dashboard and reports |
| Quiz game and notifications | 1–2 weeks | Testable MVP |
| Testing and deployment | 1 week | Stable pilot release |

A reasonable estimate for the MVP is **9–12 weeks**, depending on the size and experience of the development team.

## 8. Suggested Technical Architecture

### Frontend

- Next.js or React.
- Responsive design for desktop, tablet, and mobile devices.
- A Vietnamese-first design system.
- Optional Progressive Web App support for an app-like mobile experience.

### Backend

- NestJS or Laravel.
- Role-based API authorization.
- Audit logs for sensitive changes such as grades and attendance.

### Data and File Storage

- PostgreSQL for application data.
- S3-compatible object storage for teaching materials and student submissions.
- Scheduled backups with a documented recovery process.

### Deployment

- Cloud deployment.
- Separate development, staging, and production environments.
- Error tracking, performance monitoring, and service health monitoring.

## 9. Preliminary Data Model

The main data entities include:

- Users.
- Roles and permissions.
- Classes.
- Subjects.
- School years and semesters.
- Class memberships.
- Lessons and timetable entries.
- Assignments.
- File attachments.
- Student submissions.
- Grades and teacher feedback.
- Games, questions, and attempts.
- Attendance records.
- Notifications.
- Audit logs.

## 10. Non-Functional Requirements

- Clear permissions for administrators, teachers, students, and parents.
- Encrypted network connections and secure account management.
- Restrictions on uploaded file types and sizes.
- Malware and unsafe-file checks where applicable.
- Complete change histories for grades and attendance.
- Appropriate protection of children's personal data.
- Automated backups and tested data recovery procedures.
- Good performance on mobile networks.
- Excel and PDF report exports.
- Ability to scale as the number of classes and students grows.
- Correct Vietnamese typography, including full support for diacritics.
- Consistent Vietnamese terminology throughout the application.
- Vietnamese date, time, and number formatting.

## 11. Vietnamese Localization Guidelines

Although the project specification is written in English, the website itself must be Vietnamese-first.

- Use Vietnamese for all navigation, headings, buttons, form labels, placeholders, and system messages.
- Write error messages in clear, friendly Vietnamese and explain how the user can resolve the problem.
- Use familiar educational terms such as **Lớp học**, **Bài tập**, **Điểm danh**, **Thời khóa biểu**, and **Tiến độ học tập**.
- Display dates in a Vietnamese-friendly format, such as `08/10/2026`.
- Use the `Asia/Ho_Chi_Minh` time zone unless the deployment requirements specify otherwise.
- Design buttons and form fields to accommodate Vietnamese text without truncation.
- Store translation keys separately from application logic so another language can be added later without rebuilding the interface.
- Review final wording with Vietnamese teachers before launch.

## 12. Success Metrics

After the pilot launch, the team can monitor:

- Weekly student login rate.
- On-time assignment submission rate.
- Average time required for teachers to create and grade assignments.
- Percentage of lessons with completed attendance records.
- Number of students identified early as falling behind.
- Educational game completion rate.
- Teacher and student satisfaction.

## 13. Recommended Next Steps

1. Confirm whether the product is intended for a school, learning center, or independent teachers.
2. Finalize user roles and permissions.
3. Document the real-world assignment, grading, and attendance processes.
4. Create wireframes for approximately 8–10 core screens.
5. Test the prototype with a small group of Vietnamese teachers and students.
6. Refine the MVP scope before development begins.
7. Create a Vietnamese terminology guide for designers, developers, and testers.

