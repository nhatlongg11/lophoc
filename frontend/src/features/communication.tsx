import { StudentMessages } from "./student-messages";
import { useEffect, useState } from "react";
import { api } from "../api";
import {
  Button,
  Empty,
  ErrorBox,
  PageHead,
  Panel,
  Skeleton,
  displayDate,
  useResource,
  type Notify,
  type SchoolClass,
  type User,
} from "../components/ui";

type Announcement = {
  id: number;
  title: string;
  body: string;
  created_at: string;
  class_id?: number;
};
export function Communication({
  user,
  notify,
}: {
  user: User;
  notify: Notify;
}) {
  const [tab, setTab] = useState("announcements"),
    [busy, setBusy] = useState(false);
  const announcements = useResource<Announcement[]>("/announcements");
  const { data: classes } = useResource<SchoolClass[]>(
    ["teacher", "admin"].includes(user.role) ? "/classes" : null,
  );
  const [title, setTitle] = useState(""),
    [notice, setNotice] = useState(""),
    [classId, setClassId] = useState("");
  return (
    <>
      <PageHead
        title="Kết nối & đồng hành"
        description="Thông tin lớp học và trao đổi riêng giữa thầy cô, gia đình."
      />
      <div className="segment admin-tabs">
        <button
          className={tab === "announcements" ? "selected" : ""}
          onClick={() => setTab("announcements")}
        >
          Bảng tin
        </button>
        {user.role !== "student" && (
          <button
            className={tab === "messages" ? "selected" : ""}
            onClick={() => setTab("messages")}
          >
            Tin nhắn riêng
          </button>
        )}
      </div>
      {tab === "announcements" ? (
        <div className="two-col">
          <Panel title="Bảng tin lớp học">
            <ErrorBox message={announcements.error} />
            {announcements.loading ? (
              <Skeleton />
            ) : announcements.data?.length ? (
              announcements.data.map((a) => (
                <article className="announcement" key={a.id}>
                  <span className="eyebrow">
                    {displayDate(a.created_at)} ·{" "}
                    {a.class_id ? "Lớp học" : "Toàn trường"}
                  </span>
                  <h3>{a.title}</h3>
                  <p>{a.body}</p>
                </article>
              ))
            ) : (
              <Empty>Chưa có thông báo.</Empty>
            )}
          </Panel>
          {["teacher", "admin"].includes(user.role) && (
            <Panel title="Gửi thông báo">
              <form
                className="stack-form"
                onSubmit={async (e) => {
                  e.preventDefault();
                  setBusy(true);
                  try {
                    await api("/announcements", {
                      method: "POST",
                      body: {
                        title,
                        body: notice,
                        class_id: classId ? Number(classId) : null,
                      },
                    });
                    setTitle("");
                    setNotice("");
                    announcements.reload();
                    notify("Đã gửi thông báo.");
                  } catch (err) {
                    notify((err as Error).message, true);
                  } finally {
                    setBusy(false);
                  }
                }}
              >
                <label>
                  Phạm vi
                  <select
                    required={user.role !== "admin"}
                    value={classId}
                    onChange={(e) => setClassId(e.target.value)}
                  >
                    <option value="">
                      {user.role === "admin" ? "Toàn tổ chức" : "Chọn lớp"}
                    </option>
                    {classes?.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  Tiêu đề
                  <input
                    required
                    maxLength={180}
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                  />
                </label>
                <label>
                  Nội dung
                  <textarea
                    required
                    maxLength={10000}
                    rows={6}
                    value={notice}
                    onChange={(e) => setNotice(e.target.value)}
                  />
                </label>
                <Button disabled={busy}>Gửi thông báo</Button>
              </form>
            </Panel>
          )}
        </div>
      ) : (
        <StudentMessages user={user} notify={notify} />
      )}
    </>
  );
}

type Organization = {
  name: string;
  plan: string;
  subscription_status: string;
  leaderboard_enabled: boolean;
  max_classes: number;
  max_students: number;
  max_teachers: number;
  storage_limit_bytes: number;
};
type OrgResponse = {
  organization: Organization;
  usage: {
    students: number;
    teachers: number;
    classes: number;
    storage_bytes: number;
  };
  requests: { id: number; plan: string; status: string; created_at: string }[];
  plans: Record<
    string,
    { name: string; classes: number; students: number; teachers: number }
  >;
};
export function OrganizationSettings({ notify }: { notify: Notify }) {
  const { data, loading, error, reload } =
    useResource<OrgResponse>("/organization");
  const [name, setName] = useState(""),
    [ranking, setRanking] = useState(false),
    [email, setEmail] = useState(""),
    [role, setRole] = useState("family"),
    [studentId, setStudentId] = useState(""),
    [invite, setInvite] = useState(""),
    [busy, setBusy] = useState(false);
  const { data: students } = useResource<{ id: number; name: string }[]>(
    "/users?role=student",
  );
  useEffect(() => {
    if (data) {
      setName(data.organization.name);
      setRanking(!!data.organization.leaderboard_enabled);
    }
  }, [data]);
  return (
    <>
      <PageHead
        title="Tổ chức & gói dịch vụ"
        description="Cấu hình quyền riêng tư, giới hạn sử dụng và mời thành viên."
      />
      <ErrorBox message={error} />
      {loading ? (
        <Skeleton />
      ) : (
        data && (
          <>
            <div className="metric-grid">
              <article className="metric mint">
                <small>Học sinh hoạt động</small>
                <strong>
                  {data.usage.students}/{data.organization.max_students}
                </strong>
              </article>
              <article className="metric sky">
                <small>Lớp học hoạt động</small>
                <strong>
                  {data.usage.classes}/{data.organization.max_classes}
                </strong>
              </article>
              <article className="metric peach">
                <small>Giáo viên hoạt động</small>
                <strong>
                  {data.usage.teachers}/{data.organization.max_teachers}
                </strong>
              </article>
              <article className="metric lemon">
                <small>Dung lượng sử dụng</small>
                <strong>
                  {Math.round(data.usage.storage_bytes / 1048576)} MB
                </strong>
              </article>
            </div>
            <div className="two-col">
              <Panel title="Cấu hình tổ chức">
                <form
                  className="stack-form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    try {
                      await api("/organization", {
                        method: "PUT",
                        body: { name, leaderboard_enabled: ranking },
                      });
                      notify("Đã lưu cấu hình.");
                      reload();
                    } catch (err) {
                      notify((err as Error).message, true);
                    }
                  }}
                >
                  <label>
                    Tên tổ chức
                    <input
                      required
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                    />
                  </label>
                  <label className="check-row">
                    <input
                      type="checkbox"
                      checked={ranking}
                      onChange={(e) => setRanking(e.target.checked)}
                    />{" "}
                    Bật bảng thành tích riêng trong lớp (dùng biệt danh)
                  </label>
                  <p className="muted">
                    Gói hiện tại:{" "}
                    {data.plans[data.organization.plan]?.name ||
                      data.organization.plan}{" "}
                    ·{" "}
                    {data.organization.subscription_status === "active"
                      ? "Đang hoạt động"
                      : "Tạm ngưng"}
                  </p>
                  <Button>Lưu cấu hình</Button>
                </form>
              </Panel>
              <Panel title="Mời thành viên">
                <form
                  className="stack-form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setInvite("");
                    try {
                      const result = await api<{ url: string }>(
                        "/invitations",
                        {
                          method: "POST",
                          body: {
                            email,
                            role,
                            student_id:
                              role === "family" ? Number(studentId) : null,
                          },
                        },
                      );
                      setInvite(result.url);
                      notify("Đã tạo lời mời có hiệu lực 72 giờ.");
                    } catch (err) {
                      notify((err as Error).message, true);
                    } finally {
                      setBusy(false);
                    }
                  }}
                >
                  <label>
                    Email
                    <input
                      required
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                    />
                  </label>
                  <label>
                    Vai trò
                    <select
                      value={role}
                      onChange={(e) => setRole(e.target.value)}
                    >
                      <option value="family">Gia đình</option>
                      <option value="teacher">Giáo viên</option>
                      <option value="student">Học sinh</option>
                    </select>
                  </label>
                  {role === "family" && (
                    <label>
                      Học sinh được liên kết
                      <select
                        required
                        value={studentId}
                        onChange={(e) => setStudentId(e.target.value)}
                      >
                        <option value="">Chọn học sinh</option>
                        {students?.map((s) => (
                          <option key={s.id} value={s.id}>
                            {s.name}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                  <Button disabled={busy}>Tạo liên kết mời</Button>
                </form>
                {invite && (
                  <div className="soft-box">
                    <label>
                      Liên kết mời
                      <input
                        readOnly
                        value={invite}
                        onFocus={(e) => e.target.select()}
                      />
                    </label>
                    <p>Chia sẻ riêng liên kết này với người được mời.</p>
                  </div>
                )}
              </Panel>
            </div>
            <h2 className="section-heading">Chọn gói phù hợp</h2>
            <div className="plan-grid">
              {Object.entries(data.plans).map(([key, p]) => (
                <article className="panel" key={key}>
                  <h2>{p.name}</h2>
                  <p>
                    {p.students} học sinh · {p.classes} lớp · {p.teachers} giáo
                    viên
                  </p>
                  <Button
                    secondary
                    disabled={key === data.organization.plan}
                    onClick={async () => {
                      try {
                        const result = await api<{ message: string }>(
                          "/billing/requests",
                          { method: "POST", body: { plan: key } },
                        );
                        notify(result.message);
                        reload();
                      } catch (err) {
                        notify((err as Error).message, true);
                      }
                    }}
                  >
                    {key === data.organization.plan
                      ? "Gói hiện tại"
                      : "Yêu cầu đổi gói"}
                  </Button>
                </article>
              ))}
            </div>
            <Panel title="Lịch sử yêu cầu">
              {data.requests.length ? (
                data.requests.map((r) => (
                  <div className="list-row" key={r.id}>
                    <div>
                      <strong>{data.plans[r.plan]?.name || r.plan}</strong>
                      <small>{displayDate(r.created_at)}</small>
                    </div>
                    <span className="pill">
                      {r.status === "pending"
                        ? "Chờ xét duyệt"
                        : r.status === "approved"
                          ? "Đã duyệt"
                          : "Từ chối"}
                    </span>
                  </div>
                ))
              ) : (
                <Empty>Chưa có yêu cầu đổi gói.</Empty>
              )}
            </Panel>
          </>
        )
      )}
    </>
  );
}
