<script setup lang="ts">
type UpdateRequest = { id: string; action: string; version?: string; state: string; requested_at: string }
type HistoryEntry = { id: string; event: string; at: string; details: Record<string,string>; read?: boolean }
type RecoveryBackup = { id: string; version?: string; created_at?: string; complete: boolean }
type UpdateState = {
  current_version?: string; channel?: string; web_upgrade_enabled?: boolean
  github_reachable?: boolean; metadata_status?: string; metadata_failure?: string
  last_check?: { update_available?: boolean; latest?: { version?: string; source?: string; notes?: string }; next?: { version?: string; notes?: string }; upgrade_path?: Array<{version:string}>; metadata_status?:string; checked_at?: string }
  staged?: { ok?: boolean; id?: string; version?: string; sha256?: string; file_count?: number }
  wizard?: { operator_ready?: boolean; production_apply_enabled?: boolean; installed?: boolean; staged_installable?: boolean
    operator_status?: { status?: string; checked_at?: string; reason?: string; age_seconds?: number }; maintenance?: { enabled?: boolean; update_active?: boolean };
    rollback?: { available: boolean; transaction?: string; from_version?: string; to_version?: string } }
  policy?: {mode:string;valid:boolean;error?:string;timezone:string;days:number[];window_start?:string;window_end?:string;window_open:boolean;next_window_at?:number;defer_until?:string;blocked_versions:string[]};
  auto_stage?: {enabled:boolean;state?:string;next_attempt_at?:number}; download_progress?: {version:string;state:string;received_bytes:number;total_bytes:number}
  requests?: UpdateRequest[]; backups?: RecoveryBackup[]; downloads?: Array<{version:string}>
}
const api = useAdminApi()
const state = ref<UpdateState>({})
const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<HistoryEntry[]>([])
const nextCursor = ref<string | null>(null)
const notifications = ref<HistoryEntry[]>([])
const unreadCount = ref(0)
const notice = ref('')
const latest = computed(() => state.value.last_check?.next?.version || '')
const pending = computed(() => (state.value.requests || []).some(r => ['pending','running'].includes(r.state)))
const ready = computed(() => state.value.web_upgrade_enabled === true && state.value.wizard?.operator_ready === true && state.value.wizard?.production_apply_enabled === true && state.value.wizard?.operator_status?.status !== 'recovery_required' && !pending.value && !state.value.wizard?.maintenance?.update_active && !loading.value)
const canBackup = computed(() => ready.value)
const canRollback = computed(() => ready.value && state.value.wizard?.rollback?.available === true && !!state.value.wizard?.rollback?.transaction)
const policyAllows = (version?: string) => state.value.policy?.valid !== false && state.value.policy?.mode !== 'disabled' && !(state.value.policy?.blocked_versions || []).includes(version || '')
const canDownload = computed(() => policyAllows(latest.value) && ready.value && state.value.last_check?.update_available === true && !!latest.value && ['fresh','not_modified'].includes(state.value.last_check?.metadata_status || ''))
const canInstall = computed(() => policyAllows(state.value.staged?.version) && ready.value && state.value.wizard?.installed === true && state.value.wizard?.staged_installable === true && state.value.staged?.ok === true && !!state.value.staged?.id && !!state.value.staged?.sha256)
let timer: ReturnType<typeof setInterval> | undefined
const load = async (quiet = false) => {
  if (!quiet) loading.value = true
  try {
    const [diagnostics, audit, notices] = await Promise.all([
      api.request<{data: UpdateState}>('/updates'),
      api.request<{data: HistoryEntry[]; meta:{next_cursor:string|null}}>('/updates/history'),
      api.request<{data:{items:HistoryEntry[];unread_count:number}}>('/updates/notifications')
    ])
    state.value = diagnostics.data || {}
    history.value = audit.data || []
    nextCursor.value = audit.meta?.next_cursor || null
    notifications.value = notices.data?.items || []
    unreadCount.value = notices.data?.unread_count || 0
    if (!quiet) error.value = null
  }
  catch (e) { if (!quiet) error.value = e instanceof Error ? e.message : String(e) }
  finally { if (!quiet) loading.value = false }
}
const moreHistory = async () => {
  if (!nextCursor.value || loading.value) return
  loading.value = true
  try {
    const result = await api.request<{data:HistoryEntry[];meta:{next_cursor:string|null}}>(`/updates/history?before=${encodeURIComponent(nextCursor.value)}`)
    history.value = [...history.value, ...(result.data || [])]
    nextCursor.value = result.meta?.next_cursor || null
  } catch(e) { error.value = e instanceof Error ? e.message : String(e) }
  finally { loading.value = false }
}
const markNotificationsRead = async () => {
  await action('/updates/notifications/read', {}, 'Уведомления отмечены прочитанными.')
}
const action = async (endpoint: string, data: Record<string, unknown>, message: string) => {
  if (loading.value) return
  loading.value = true; error.value = null; notice.value = ''
  try {
    await api.request(endpoint, {method:'POST',body:JSON.stringify(data)})
    notice.value = message
    await load(true)
  } catch(e) { error.value = e instanceof Error ? e.message : String(e) }
  finally {loading.value=false}
}
const check = async () => action('/updates/check', {}, 'GitHub проверен.')
const download = async () => {
  const version = latest.value
  if (!version || !window.confirm(`Скачать и проверить CajeerEngine ${version}?`)) return
  await action('/updates/download',{confirm:true,version},'Загрузка добавлена в очередь.')
}
const install = async () => {
  const stage = state.value.staged
  if (!stage?.id || !stage.sha256 || !window.confirm(`Установить CajeerEngine ${stage.version}? Сайт временно перейдёт в режим обслуживания и создаст backup.`))return
  await action('/updates/apply',{confirm:true,package:stage.id,sha256:stage.sha256},'Установка добавлена в очередь.')
}
const backup = async () => {
  if (!window.confirm('Создать полную резервную копию кода, данных и базы? Операция выполняется фоновой службой.')) return
  await action('/updates/backup', {confirm:true}, 'Создание резервной копии поставлено в очередь.')
}
const rollback = async () => {
  const candidate = state.value.wizard?.rollback
  if (!candidate?.available || !candidate.transaction) return
  if (!window.confirm(`Откатить ${candidate.from_version} до ${candidate.to_version}? Все изменения сайта и базы данных после обновления будут утрачены.`)) return
  if (window.prompt('Для подтверждения необратимой потери данных введите ОТКАТ') !== 'ОТКАТ') return
  await action('/updates/rollback', {confirm:true,transaction:candidate.transaction}, 'Откат поставлен в очередь. Не отключайте обслуживание до завершения восстановления.')
}
const maintenance = async (enabled: boolean) => {
  if (!window.confirm(enabled?'Включить режим обслуживания?':'Отключить режим обслуживания?'))return
  await action('/updates/maintenance/'+(enabled?'on':'off'),{},'Режим обслуживания изменён.')
}
onMounted(() => { void load(); timer=setInterval(() => { if (document.visibilityState === 'visible' && !loading.value) void load(true) }, 5000) })
onUnmounted(() => {if(timer)clearInterval(timer)})
</script>
<template>
  <section class="ce-updates">
    <h1>Центр обновлений</h1>
    <p>Подписанные пакеты GitHub Releases. Все операции загрузки и установки выполняются отдельным оператором.</p>
    <p v-if="error" class="ce-error" role="alert">{{ error }}</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <div class="ce-card-grid">
      <AdminCard label="Установлена" :value="state.current_version || '—'" />
      <AdminCard label="Следующий шаг" :value="latest || '—'" />
      <AdminCard label="Канал" :value="state.channel || 'stable'" />
      <AdminCard label="Оператор" :value="state.wizard?.operator_status?.status || 'нет данных'" />
    </div>
    <div class="ce-panel">
      <h2>Новый релиз</h2>
      <p>Последний: {{ state.last_check?.latest?.version || "—" }} · План: {{ state.last_check?.upgrade_path?.map(s => s.version).join(" → ") || "—" }}</p>
      <p>Совместимость подтверждается по подписанному пакету.</p>
      <p>GitHub: {{ state.github_reachable === true ? 'доступен' : 'нет свежих данных' }} · {{ state.metadata_status || 'неизвестно' }}</p>
      <p v-if="state.metadata_failure" class="ce-error">{{ state.metadata_failure }}</p>
      <details v-if="state.last_check?.next?.notes"><summary>Описание релиза</summary><pre>{{ state.last_check.next.notes.slice(0,4000) }}</pre></details>
      <p>Подпись: {{ state.wizard?.production_apply_enabled ? 'ключ настроен' : 'отсутствует доверенный ключ' }}</p>
      <p>Оператор: {{ state.wizard?.operator_ready ? 'готов' : (state.wizard?.operator_status?.reason || 'недоступен') }} · {{ state.wizard?.operator_status?.checked_at || 'нет heartbeat' }}</p>
      <p>Политика: {{ state.policy?.mode || 'legacy' }} · {{ state.policy?.valid === false ? state.policy.error : 'действует' }}. Автономная установка отключена.</p>
      <p>Окно обслуживания: {{ state.policy?.timezone }} · {{ state.policy?.days.join(', ') }} · {{ state.policy?.window_start || '00:00' }}–{{ state.policy?.window_end || '24:00' }} · {{ state.policy?.window_open === false ? 'закрыто' : 'открыто' }}</p>
      <p v-if="state.policy?.next_window_at && state.policy.window_open === false">Следующее окно: {{ new Date(state.policy.next_window_at * 1000).toLocaleString() }}</p>
      <p v-if="state.policy?.defer_until">Отложено до {{ state.policy.defer_until }}</p>
      <p v-if="state.policy?.blocked_versions.length">Заблокированные версии: {{ state.policy.blocked_versions.join(', ') }}</p>
      <p>Автоподготовка: {{ state.auto_stage?.enabled ? (state.auto_stage.state || "ожидание") : "выключена" }}</p>
      <div v-if="state.download_progress?.total_bytes"><p>{{ state.download_progress.version }} · {{ state.download_progress.state }} · {{ state.download_progress.received_bytes }} / {{ state.download_progress.total_bytes }} байт</p><progress :max="state.download_progress.total_bytes" :value="state.download_progress.received_bytes" /></div>
      <button :disabled="loading" @click="check">Проверить сейчас</button>
      <button :disabled="!canDownload" @click="download">Скачать и подготовить {{ latest }}</button>
      <p v-if="!state.wizard?.operator_ready">Проверьте UPDATE_WEB_UPGRADE_ENABLED, UPDATE_OPERATOR_ENABLED, состояние systemd timer и recovery-журнал. Без свежего heartbeat очередь не принимается.</p>
    </div>
    <div class="ce-panel">
      <h2>Установка</h2>
      <p>Подготовлен: {{ state.staged?.ok ? state.staged.version : 'отсутствует' }}</p>
      <p>Последние записи о скачанных релизах: {{ state.downloads?.length || 0 }}</p>
      <p v-if="state.staged?.sha256">SHA-256: <code>{{ state.staged.sha256 }}</code></p>
      <button :disabled="!canInstall" @click="install">Установить обновление</button>
      <p>Установка требует повторного подтверждения входа и включает backup, maintenance и проверку после установки.</p>
      <p v-if="state.wizard?.operator_status?.status === 'recovery_required'" class="ce-error">Прерванная операция: требуется ручное восстановление.</p>
    </div>
    <div class="ce-panel">
      <h2>Последние операции</h2>
      <div class="ce-table-wrap"><table><thead><tr><th>Запрос</th><th>Тип</th><th>Версия</th><th>Состояние</th><th>Создано</th></tr></thead>
        <tbody><tr v-for="item in state.requests || []" :key="item.id"><td>{{ item.id.slice(0, 12) }}</td><td>{{ item.action }}</td><td>{{ item.version || '—' }}</td><td>{{ item.state }}</td><td>{{ item.requested_at }}</td></tr>
          <tr v-if="!state.requests?.length"><td colspan="5">Операций нет</td></tr>
        </tbody></table></div>
    </div>
    <div class="ce-panel">
      <h2>Уведомления об обновлениях ({{ unreadCount }} непрочитанных)</h2>
      <button :disabled="loading || unreadCount === 0" @click="markNotificationsRead">Отметить все прочитанными</button>
      <p v-if="!notifications.length">Уведомлений пока нет.</p>
      <ul v-else><li v-for="item in notifications" :key="item.id">
        <strong>{{ item.read ? 'Прочитано' : 'Новое' }}</strong> · {{ item.event }} · {{ item.details.version || item.details.transaction_id || item.details.request_id || '—' }} · {{ item.at }}
      </li></ul>
      <p>Webhook-уведомления отправляются через очередь Event API; для доставки должен работать scheduler и queue worker.</p>
    </div>
    <div class="ce-panel">
      <h2>История и журнал действий</h2>
      <div class="ce-table-wrap"><table><thead><tr><th>Дата</th><th>Событие</th><th>Действие</th><th>Инициатор</th><th>Версия</th><th>Идентификатор</th></tr></thead>
      <tbody><tr v-for="item in history" :key="item.id"><td>{{ item.at }}</td><td>{{ item.event }}</td><td>{{ item.details.action || '—' }}</td><td>{{ item.details.actor || '—' }}</td><td>{{ item.details.version || '—' }}</td><td>{{ (item.details.request_id || item.details.transaction_id || item.id).slice(0,20) }}</td></tr>
      <tr v-if="!history.length"><td colspan="6">Записей нет</td></tr></tbody></table></div>
      <button :disabled="loading || !nextCursor" @click="moreHistory">Загрузить более ранние записи</button>
    </div>
    <div class="ce-panel">
      <h2>Обслуживание</h2>
      <p>{{ state.wizard?.maintenance?.enabled ? 'Включено' : 'Выключено' }}</p>
      <button :disabled="loading" @click="maintenance(true)">Включить</button>
      <button :disabled="loading || state.wizard?.maintenance?.update_active" @click="maintenance(false)">Выключить</button>
      <h3>Резервные копии</h3>
      <button :disabled="!canBackup" @click="backup">Создать резервную копию</button>
      <p v-if="!state.backups?.length">Сохранённых резервных копий пока нет.</p>
      <ul v-else><li v-for="item in state.backups" :key="item.id">{{ item.id }} · {{ item.version || '—' }} · {{ item.complete ? 'создана' : 'неполная' }}</li></ul>
      <h3>Откат последнего обновления</h3>
      <p v-if="state.wizard?.rollback?.available">{{ state.wizard.rollback.from_version }} → {{ state.wizard.rollback.to_version }}. Изменения после установки будут потеряны.</p>
      <p v-else>Нет последнего завершённого обновления, доступного для отката.</p>
      <button :disabled="!canRollback" @click="rollback">Откатить обновление</button>
      <p>Откат выполняет оператор с проверкой MAC, файлов и базы данных. При ошибке восстановления сайт остаётся в режиме обслуживания; аварийное восстановление — через CLI/runbook.</p>
    </div>
  </section>
</template>
