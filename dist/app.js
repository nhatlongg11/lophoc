const navItems = [...document.querySelectorAll('[data-view]')];
const views = [...document.querySelectorAll('.view')];
const sidebar = document.getElementById('sidebar');
const toast = document.getElementById('toast');
let toastTimer;

function showView(id) {
  const target = document.getElementById(id);
  if (!target) return;
  views.forEach(view => view.classList.toggle('active', view === target));
  document.querySelectorAll('.nav-item').forEach(item => item.classList.toggle('active', item.dataset.view === id));
  sidebar.classList.remove('open');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function notify(message) {
  toast.querySelector('p').textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2800);
}

navItems.forEach(item => item.addEventListener('click', () => showView(item.dataset.view)));
document.getElementById('menuBtn').addEventListener('click', () => sidebar.classList.toggle('open'));

document.querySelectorAll('[data-modal]').forEach(button => button.addEventListener('click', () => {
  document.getElementById(button.dataset.modal).classList.add('open');
}));
document.querySelectorAll('.modal-close').forEach(button => button.addEventListener('click', () => button.closest('.modal-backdrop').classList.remove('open')));
document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.addEventListener('click', event => {
  if (event.target === backdrop) backdrop.classList.remove('open');
}));
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(modal => modal.classList.remove('open'));
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault();
    document.getElementById('globalSearch').focus();
  }
});

document.querySelectorAll('[data-toast]').forEach(button => button.addEventListener('click', () => notify(button.dataset.toast)));
document.querySelectorAll('.task-row').forEach(row => row.addEventListener('click', () => {
  row.style.opacity = '.45';
  row.querySelector('.task-check').textContent = '✓';
  notify('Đã đánh dấu công việc hoàn thành');
}));

document.getElementById('publishAssignment').addEventListener('click', () => {
  const title = document.getElementById('assignmentTitle');
  if (!title.value.trim()) {
    title.focus();
    title.style.borderColor = '#c9514b';
    notify('Vui lòng nhập tên bài tập trước khi giao bài');
    return;
  }
  document.getElementById('assignmentModal').classList.remove('open');
  notify('Đã giao bài và gửi thông báo đến học sinh');
  title.value = '';
});

const students = [
  ['NA','Nguyễn Ngọc Anh','Có mặt',''],['BT','Phạm Bảo Trâm','Có mặt',''],['DK','Lê Đăng Khoa','Có mặt',''],['HL','Trần Hoàng Long','Đi muộn','Đến lớp lúc 09:08'],['KT','Vũ Khánh Trang','Có mặt',''],['MT','Nguyễn Minh Thành','Vắng có phép','Khám bệnh'],['QN','Hoàng Quỳnh Như','Có mặt',''],['TA','Đỗ Tuấn Anh','Có mặt','']
];
const attendanceRows = document.getElementById('attendanceRows');

function renderAttendance() {
  attendanceRows.innerHTML = students.map((student, index) => `<tr><td><span class="avatar ${student[2].includes('Vắng') ? 'red' : student[2] === 'Đi muộn' ? 'yellow' : ''}">${student[0]}</span><strong>${student[1]}</strong></td><td><select data-attendance="${index}"><option ${student[2] === 'Có mặt' ? 'selected' : ''}>Có mặt</option><option ${student[2] === 'Đi muộn' ? 'selected' : ''}>Đi muộn</option><option ${student[2] === 'Vắng có phép' ? 'selected' : ''}>Vắng có phép</option><option>Vắng không phép</option></select></td><td><input data-note="${index}" value="${student[3]}" placeholder="Thêm ghi chú…"></td><td><small>${index < 3 ? 'Vừa cập nhật' : '09:02'}</small></td></tr>`).join('');
  document.querySelectorAll('[data-attendance]').forEach(select => select.addEventListener('change', () => {
    students[Number(select.dataset.attendance)][2] = select.value;
    updateAttendanceSummary();
  }));
}

function updateAttendanceSummary() {
  const counts = students.reduce((acc, student) => {
    if (student[2] === 'Có mặt') acc.present++;
    else if (student[2] === 'Đi muộn') acc.late++;
    else acc.absent++;
    return acc;
  }, {present: 30, late: 0, absent: 0});
  document.getElementById('presentCount').textContent = counts.present;
  document.getElementById('lateCount').textContent = counts.late;
  document.getElementById('absentCount').textContent = counts.absent;
}
renderAttendance();
document.getElementById('saveAttendance').addEventListener('click', () => notify('Đã lưu điểm danh lớp 8A2 lúc 09:12'));

document.getElementById('markRead').addEventListener('click', () => {
  document.querySelectorAll('.notification.unread').forEach(item => item.classList.remove('unread'));
  document.querySelectorAll('.notification > i').forEach(dot => dot.remove());
  notify('Đã đánh dấu tất cả thông báo là đã đọc');
});

const quiz = [
  {q:'Nghiệm của phương trình 2x + 4 = 10 là bao nhiêu?', a:['x = 2','x = 3','x = 5','x = 7'], c:1},
  {q:'Biểu thức nào tương đương với 3(x + 2)?', a:['3x + 2','3x + 5','3x + 6','x + 6'], c:2},
  {q:'Nếu x² = 49 và x > 0, giá trị của x là bao nhiêu?', a:['5','6','7','8'], c:2}
];
let quizIndex = 0;
let quizScore = 0;

function openQuiz() {
  quizIndex = 0;
  quizScore = 0;
  renderQuiz();
  document.getElementById('quizModal').classList.add('open');
}

function renderQuiz() {
  const item = quiz[quizIndex];
  document.getElementById('quizNumber').textContent = quizIndex + 1;
  document.getElementById('quizQuestion').textContent = item.q;
  document.getElementById('quizProgress').style.width = `${(quizIndex + 1) / quiz.length * 100}%`;
  const next = document.getElementById('nextQuestion');
  next.disabled = true;
  next.textContent = quizIndex === quiz.length - 1 ? 'Xem kết quả' : 'Tiếp tục';
  document.getElementById('quizAnswers').innerHTML = item.a.map((answer, index) => `<button class="answer" data-answer="${index}"><b>${String.fromCharCode(65 + index)}.</b> ${answer}</button>`).join('');
  document.querySelectorAll('[data-answer]').forEach(button => button.addEventListener('click', () => {
    if ([...document.querySelectorAll('[data-answer]')].some(answer => answer.classList.contains('selected'))) return;
    const selected = Number(button.dataset.answer);
    button.classList.add('selected', selected === item.c ? 'correct' : 'wrong');
    document.querySelector(`[data-answer="${item.c}"]`).classList.add('correct');
    if (selected === item.c) quizScore++;
    next.disabled = false;
  }));
}

document.getElementById('nextQuestion').addEventListener('click', () => {
  if (quizIndex < quiz.length - 1) {
    quizIndex++;
    renderQuiz();
  } else {
    document.getElementById('quizModal').classList.remove('open');
    notify(`Hoàn thành thử thách: ${quizScore}/${quiz.length} câu đúng`);
  }
});
document.getElementById('startQuiz').addEventListener('click', openQuiz);
document.getElementById('heroQuiz').addEventListener('click', openQuiz);

document.getElementById('globalSearch').addEventListener('keydown', event => {
  if (event.key === 'Enter' && event.target.value.trim()) {
    notify(`Đang tìm kiếm “${event.target.value.trim()}”`);
    showView('classes');
  }
});
