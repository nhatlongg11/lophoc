import { ParentAnalytics, type ChildAnalytics } from "./parent-analytics";
import { ParentArcadeProgress } from "./learning-arcade/ParentProgress";
import { Icon } from "../components/Icon";
import { Brand } from "../components/Brand";
import { useEffect, useState } from "react";
import { api } from "../api";
import { QuestArt } from "../components/QuestArt";
import {
  Button,
  ErrorBox,
  Modal,
  PageHead,
  Panel,
  Skeleton,
  Empty,
  useResource,
  displayDate,
  type User,
  type Notify,
} from "../components/ui";

export function AuthScreen({ onLogin }: { onLogin: (user: User) => void }) {
  const params = new URLSearchParams(window.location.search);
  const registration = useResource<{ enabled: boolean; approval_required: boolean }>("/registration/status");
  const requestedRegistration = params.has("register") && !params.has("invite") && !params.has("reset");
  const [mode, setMode] = useState(
    params.has("invite") ? "invite" : params.has("reset") ? "reset" : "login",
  );
  useEffect(() => {
    if (registration.data?.enabled && requestedRegistration) setMode("register");
  }, [registration.data?.enabled, requestedRegistration]);
  const [email, setEmail] = useState(params.get("email") || ""),
    [password, setPassword] = useState(""),
    [name, setName] = useState(""),
    [pin, setPin] = useState(""),
    [role, setRole] = useState("student");
  const [confirmation, setConfirmation] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState("");
  const titles: Record<string, string> = {
    login: "Bắt đầu hành trình mới.",
    forgot: "Tìm lại tài khoản",
    reset: "Tạo mật khẩu mới",
    invite: "Chào mừng đến CE - Connecting Education",
    register: "Tạo tài khoản của bạn",
  };
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    try {
      if (mode === "login") {
        const result = await api<{ user: User }>("/login", {
          method: "POST",
          body: { email, password },
        });
        onLogin(result.user);
      } else if (mode === "register") {
        const result = await api<{ message: string }>("/register", {
          method: "POST",
          body: { name, email, password, password_confirmation: confirmation, role, parent_pin: role === "parent" ? pin : null },
        });
        setMode("login");
        setEmail(email.trim().toLowerCase());
        setPassword("");
        setConfirmation("");
        setPin("");
        setMessage(result.message);
        window.history.replaceState({}, "", window.location.pathname);
      } else if (mode === "forgot") {
        const result = await api<{ message: string }>("/forgot-password", {
          method: "POST",
          body: { email },
        });
        setMessage(result.message);
      } else if (mode === "reset") {
        const result = await api<{ message: string }>("/reset-password", {
          method: "POST",
          body: {
            email,
            token: params.get("reset"),
            password,
            password_confirmation: confirmation,
          },
        });
        setMessage(result.message);
        setMode("login");
        setPassword("");
        window.history.replaceState({}, "", window.location.pathname);
      } else {
        const result = await api<{ message: string; email: string }>(
          "/invitations/accept",
          {
            method: "POST",
            body: {
              token: params.get("invite"),
              name,
              password,
              password_confirmation: confirmation,
              parent_pin: pin || null,
            },
          },
        );
        setMessage(result.message);
        setEmail(result.email);
        setMode("login");
        setPassword("");
        window.history.replaceState({}, "", window.location.pathname);
      }
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <main className="login-screen">
      <div className="login-card">
        <Brand large />
        <span className="eyebrow">MỖI NGÀY, MỘT ĐIỀU MỚI</span>
        <h1>{titles[mode]}</h1>
        <p>Một không gian kết nối thầy cô, học sinh và gia đình.</p>
        <form onSubmit={submit}>
          {["invite", "register"].includes(mode) && (
            <label>
              Họ và tên
              <input
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoComplete="name"
              />
            </label>
          )}
          {mode !== "invite" && (
            <label>
              Email
              <input
                required
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                autoComplete="username"
              />
            </label>
          )}
          {mode !== "forgot" && (
            <label>
              Mật khẩu
              <input
                required
                minLength={["invite", "reset", "register"].includes(mode) ? 12 : undefined}
                maxLength={mode === "login" ? undefined : 128}
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete={
                  mode === "login" ? "current-password" : "new-password"
                }
              />
            </label>
          )}
          {["invite", "reset", "register"].includes(mode) && (
            <label>
              Nhập lại mật khẩu
              <input
                required
                type="password"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                autoComplete="new-password"
              />
            </label>
          )}
          {mode === "register" && <label>Vai trò<select value={role} onChange={e => setRole(e.target.value)}><option value="student">Học sinh</option><option value="teacher">Giáo viên</option><option value="parent">Phụ huynh</option></select></label>}
          {(mode === "invite" || mode === "register" && role === "parent") && (
            <label>
              PIN phụ huynh (tài khoản gia đình)
              <input
                type="password"
                required={mode === "register"}
                inputMode="numeric"
                pattern="[0-9]{6,12}"
                minLength={6}
                maxLength={12}
                value={pin}
                onChange={(e) => setPin(e.target.value)}
                autoComplete="off"
              />
            </label>
          )}
          {mode === "register" && <p className="muted">Mật khẩu tối thiểu 12 ký tự. Tài khoản cần được quản trị viên duyệt trước khi đăng nhập.{role === "parent" && " Quản trị viên sẽ liên kết tài khoản với học sinh để bạn xem thông tin của con."}</p>}
          <ErrorBox message={error} />
          {message && (
            <p className="success-box" role="status">
              {message}
            </p>
          )}
          <Button disabled={busy}>
            {busy
              ? "Đang xử lý…"
              : {
                  login: (
                    <>
                      Đăng nhập <Icon name="arrow_right" />
                    </>
                  ),
                  forgot: "Gửi hướng dẫn",
                  reset: "Lưu mật khẩu",
                  invite: "Tạo tài khoản",
                  register: "Gửi yêu cầu đăng ký",
                }[mode]}
          </Button>
        </form>
        <button
          className="text-link auth-link"
          onClick={() => {
            setMode(mode === "login" ? "forgot" : "login");
            setError("");
          }}
        >
          {mode === "login" ? (
            "Quên mật khẩu?"
          ) : (
            <>
              <Icon name="arrow_left" /> Quay lại đăng nhập
            </>
          )}
        </button>
        {mode === "login" && registration.data?.enabled && <button className="text-link auth-link" onClick={() => { setMode("register"); setError(""); setMessage(""); setPassword(""); }}>Chưa có tài khoản? Đăng ký</button>}
      </div>
      <div className="login-art">
        <span>KHƠI DẬY NIỀM VUI HỌC TẬP</span>
        <h2>
          Kiến thức mở lối.
          <br />
          Tò mò dẫn đường.
        </h2>
        <p>Học tập · Khám phá · Trưởng thành</p>
        <QuestArt />
        <div className="art-grid">
          <i>∑</i>
          <i>Aa</i>
          <i>
            <Icon name="sparkle" />
          </i>
          <i>π</i>
        </div>
      </div>
    </main>
  );
}

export function FamilySelector({
  onUser,
  onClose,
}: {
  onUser: (user: User) => void;
  onClose?: () => void;
}) {
  const { data, error, loading } = useResource<{
    children: { id: number; name: string }[];
  }>("/family/profiles");
  const [pin, setPin] = useState(""),
    [failure, setFailure] = useState(""),
    [busy, setBusy] = useState(false);
  async function choose(mode: string, student_id?: number) {
    setBusy(true);
    setFailure("");
    try {
      const result = await api<{ user: User }>("/family/select", {
        method: "POST",
        body: { mode, student_id, pin },
      });
      onUser({ ...result.user, is_family_session: true });
      onClose?.();
    } catch (err) {
      setFailure((err as Error).message);
    } finally {
      setBusy(false);
      setPin("");
    }
  }
  const content = (
    <>
      <p>Chọn hồ sơ để tiếp tục. Chế độ phụ huynh được bảo vệ riêng.</p>
      <ErrorBox message={error || failure} />
      {loading ? (
        <Skeleton />
      ) : (
        <div className="profile-choices">
          {data?.children.map((child) => (
            <button
              className="profile-choice"
              key={child.id}
              disabled={busy}
              onClick={() => choose("student", child.id)}
            >
              <span className="profile-icon">
                <Icon name="sparkle" />
              </span>
              <strong>{child.name}</strong>
              <small>
                Vào không gian học tập <Icon name="arrow_right" />
              </small>
            </button>
          ))}
          {!data?.children.length && (
            <Empty>
              Chưa liên kết học sinh. Vui lòng liên hệ quản trị viên.
            </Empty>
          )}
        </div>
      )}
      <form
        className="parent-unlock"
        onSubmit={(e) => {
          e.preventDefault();
          choose("parent");
        }}
      >
        <h3>Dành cho phụ huynh</h3>
        <label>
          PIN phụ huynh
          <input
            type="password"
            inputMode="numeric"
            pattern="[0-9]{6,12}"
            minLength={6}
            maxLength={12}
            autoComplete="off"
            required
            value={pin}
            onChange={(e) => setPin(e.target.value)}
          />
        </label>
        <Button disabled={busy}>Mở sổ liên lạc</Button>
        <p className="muted">Quyền truy cập phụ huynh có hiệu lực 15 phút.</p>
      </form>
    </>
  );
  return onClose ? (
    <Modal title="Không gian gia đình" onClose={onClose}>
      {content}
    </Modal>
  ) : (
    <main className="family-screen">
      <div className="panel">
        <span className="eyebrow">CE - Connecting Education · GIA ĐÌNH</span>
        <h1>Hôm nay, ai đang học?</h1>
        {content}
      </div>
    </main>
  );
}

type Child = {
  analytics: ChildAnalytics;
  id: number;
  name: string;
  results: {
    id: number;
    title: string;
    status: string;
    score: number | null;
    max_score: number;
    feedback?: string;
  }[];
  attendance: {
    status: string;
    note?: string;
    title: string;
    starts_at: string;
  }[];
  timetable: { id: number; title: string; starts_at: string; room?: string }[];
  homework: { id: number; title: string; due_at: string }[];
  observations: { id: number; note: string; plan?: string }[];
};
export function ParentDashboard() {
  const { data, error, loading } = useResource<{ children: Child[] }>(
      "/family/dashboard",
    ),
    [selected, setSelected] = useState(0);
  const child =
    data?.children.find((c) => c.id === selected) || data?.children[0];
  return (
    <>
      <div className="parent-page-header">
        <PageHead
          title="Đồng hành cùng con"
          description="Sổ liên lạc, kết quả và nhịp học mỗi ngày."
          action={
            <label className="child-picker">
              Hồ sơ con
              <select
                value={child?.id || ""}
                onChange={(e) => setSelected(Number(e.target.value))}
              >
                {data?.children.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </label>
          }
        />
        <QuestArt variant="star" />
      </div>
      <ErrorBox message={error} />
      {loading ? (
        <Skeleton />
      ) : child ? (
        <>
          <div className="metric-grid">
            <article className="metric mint">
              <span>
                <Icon name="check" />
              </span>
              <small>Bài đã nộp</small>
              <strong>
                {child.analytics.submitted_count}/
                {child.analytics.assigned_count}
              </strong>
            </article>
            <article className="metric sky">
              <span>
                <Icon name="progress" />
              </span>
              <small>Kết quả đã chấm</small>
              <strong>{child.analytics.graded_count}</strong>
            </article>
            <article className="metric peach">
              <span>
                <Icon name="attendance" />
              </span>
              <small>Buổi có mặt / ghi nhận</small>
              <strong>
                {child.analytics.attendance_present}/
                {child.analytics.attendance_recorded}
              </strong>
            </article>
            <article className="metric lemon">
              <span>
                <Icon name="schedule" />
              </span>
              <small>Lịch học tuần này</small>
              <strong>{child.timetable.length}</strong>
            </article>
          </div>
          <div className="two-col">
            <Panel title="Kết quả gần đây">
              {child.results.map((r) => (
                <div className="list-row" key={r.id}>
                  <div>
                    <strong>{r.title}</strong>
                    <small>
                      {r.feedback ||
                        (r.status === "graded"
                          ? "Đã chấm"
                          : "Chờ giáo viên chấm")}
                    </small>
                  </div>
                  <b>{r.score === null ? "—" : Number(r.score) + "/" + Number(r.max_score)}</b>
                </div>
              ))}
              {!child.results.length && <Empty>Chưa có bài nộp.</Empty>}
            </Panel>
            <Panel title="Điểm danh">
              {child.attendance.map((a, i) => (
                <div className="list-row" key={i}>
                  <div>
                    <strong>{a.title}</strong>
                    <small>
                      {displayDate(a.starts_at)} {a.note && "· " + a.note}
                    </small>
                  </div>
                  <span className="pill">
                    {{
                      present: "Có mặt",
                      late: "Đi muộn",
                      excused: "Vắng có phép",
                      unexcused: "Vắng",
                      absent: "Vắng",
                    }[a.status as "present"] || a.status}
                  </span>
                </div>
              ))}
              {!child.attendance.length && <Empty>Chưa có điểm danh.</Empty>}
            </Panel>
            <Panel title="Lịch học của con">
              {child.timetable.map((l) => (
                <div className="list-row" key={l.id}>
                  <div>
                    <strong>{l.title}</strong>
                    <small>
                      {displayDate(l.starts_at)} · {l.room || "Chưa có phòng"}
                    </small>
                  </div>
                </div>
              ))}
              {!child.timetable.length && <Empty>Chưa có lịch tuần này.</Empty>}
            </Panel>
            <ParentArcadeProgress studentId={child.id} />
            <ParentAnalytics data={child.analytics} />
            <Panel title="Nhận xét của giáo viên">
              {child.observations.map((n) => (
                <div className="soft-box" key={n.id}>
                  <p>{n.note}</p>
                  {n.plan && (
                    <p>
                      <b>Kế hoạch: </b>
                      {n.plan}
                    </p>
                  )}
                </div>
              ))}
              {!child.observations.length && <Empty>Chưa có nhận xét.</Empty>}
            </Panel>
          </div>
        </>
      ) : (
        <Empty>Chưa có hồ sơ học sinh.</Empty>
      )}
    </>
  );
}

export function AccountSecurity({ notify }: { notify: Notify }) {
  const [current, setCurrent] = useState(""),
    [password, setPassword] = useState(""),
    [confirmation, setConfirmation] = useState(""),
    [busy, setBusy] = useState(false);
  return (
    <Panel title="Bảo mật tài khoản">
      <form
        className="security-form"
        onSubmit={async (e) => {
          e.preventDefault();
          setBusy(true);
          try {
            await api("/account/password", {
              method: "PUT",
              body: {
                current_password: current,
                password,
                password_confirmation: confirmation,
              },
            });
            setCurrent("");
            setPassword("");
            setConfirmation("");
            notify("Đã đổi mật khẩu và thu hồi các phiên khác.");
          } catch (err) {
            notify((err as Error).message, true);
          } finally {
            setBusy(false);
          }
        }}
      >
        <label>
          Mật khẩu hiện tại
          <input
            required
            type="password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
          />
        </label>
        <label>
          Mật khẩu mới (ít nhất 12 ký tự)
          <input
            required
            minLength={12}
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </label>
        <label>
          Nhập lại mật khẩu mới
          <input
            required
            minLength={12}
            type="password"
            autoComplete="new-password"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
          />
        </label>
        <Button disabled={busy}>Đổi mật khẩu</Button>
      </form>
    </Panel>
  );
}
