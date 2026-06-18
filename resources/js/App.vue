<script setup>
import { computed, onMounted, reactive, ref } from 'vue';

const user = ref(null);
const booting = ref(true);
const authLoading = ref(false);
const saving = ref(false);
const loadingReviews = ref(false);
const organization = ref(null);
const reviews = ref([]);
const reviewsMeta = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0,
});
const loginForm = reactive({
    email: '',
    password: '',
});
const settingsForm = reactive({
    url: '',
});
const error = ref('');
const fieldErrors = ref({});

const isAuthed = computed(() => Boolean(user.value));

const pages = computed(() => {
    const total = reviewsMeta.last_page || 1;
    const current = reviewsMeta.current_page || 1;
    const start = Math.max(1, current - 2);
    const end = Math.min(total, start + 4);
    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
});

function starsArray(rating) {
    return Array.from({ length: 5 }, (_, i) => i < (rating ?? 0));
}

function avatarLetter(author) {
    return (author || '?').trim()[0].toUpperCase();
}

function avatarColor(author) {
    const colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444'];
    let h = 0;
    for (const c of (author || '')) h = (h * 31 + c.charCodeAt(0)) & 0xffffffff;
    return colors[Math.abs(h) % colors.length];
}

function messageFrom(e) {
    return e?.response?.data?.message || 'Запрос не выполнен.';
}
function setValidationErrors(e) {
    fieldErrors.value = e?.response?.data?.errors || {};
}

async function loadUser() {
    try {
        const r = await window.axios.get('/api/user');
        user.value = r.data.user;
    } catch { user.value = null; }
}

async function loadOrganization() {
    const r = await window.axios.get('/api/organization');
    organization.value = r.data.organization;
    if (organization.value) {
        settingsForm.url = organization.value.source_url;
        await loadReviews(1);
    }
}

async function login() {
    authLoading.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        await window.axios.get('/sanctum/csrf-cookie');
        const r = await window.axios.post('/api/login', loginForm);
        user.value = r.data.user;
        await loadOrganization();
    } catch (e) {
        setValidationErrors(e);
        error.value = messageFrom(e);
    } finally { authLoading.value = false; }
}

async function logout() {
    await window.axios.post('/api/logout');
    user.value = null;
    organization.value = null;
    reviews.value = [];
    settingsForm.url = '';
}

async function saveOrganization() {
    saving.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        const r = await window.axios.post('/api/organization', { url: settingsForm.url });
        organization.value = r.data.organization;
        await loadReviews(1);
    } catch (e) {
        setValidationErrors(e);
        error.value = messageFrom(e);
    } finally { saving.value = false; }
}

async function loadReviews(page) {
    if (!organization.value) return;
    loadingReviews.value = true;
    error.value = '';
    try {
        const r = await window.axios.get(`/api/organizations/${organization.value.id}/reviews`, {
            params: { page, per_page: 50 },
        });
        reviews.value = r.data.data;
        Object.assign(reviewsMeta, r.data.meta);
    } catch (e) {
        error.value = messageFrom(e);
    } finally { loadingReviews.value = false; }
}

onMounted(async () => {
    await loadUser();
    if (user.value) await loadOrganization();
    booting.value = false;
});
</script>

<template>
    <div class="app">

        <!-- ░░ Booting ░░ -->
        <div v-if="booting" class="splash">
            <div class="splash-spinner"></div>
        </div>

        <!-- ░░ Auth ░░ -->
        <div v-else-if="!isAuthed" class="auth-layout">
            <div class="auth-deco">
                <div class="auth-deco-orb orb-1"></div>
                <div class="auth-deco-orb orb-2"></div>
                <div class="auth-deco-inner">
                    <p class="auth-deco-ico">Y</p>
                    <p class="auth-deco-title">Yandex Reviews</p>
                    <p class="auth-deco-sub">Парсинг отзывов с Яндекс.Карт</p>
                </div>
            </div>

            <div class="auth-form-side">
                <form class="auth-card" @submit.prevent="login">
                    <div class="auth-card-header">
                        <span class="badge">Войти</span>
                        <h1>Добро пожаловать</h1>
                        <p>Введите данные для входа в систему</p>
                    </div>

                    <div class="field-group">
                        <label class="field">
                            <span class="field-label">Email</span>
                            <div class="field-wrap" :class="{ 'field-wrap--error': fieldErrors.email }">
                                <svg class="field-icon" viewBox="0 0 20 20" fill="none"><path d="M3 5h14v10H3V5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3 5l7 7 7-7" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                                <input v-model="loginForm.email" type="email" autocomplete="username" placeholder="you@example.com">
                            </div>
                            <span v-if="fieldErrors.email" class="field-error">{{ fieldErrors.email[0] }}</span>
                        </label>

                        <label class="field">
                            <span class="field-label">Пароль</span>
                            <div class="field-wrap" :class="{ 'field-wrap--error': fieldErrors.password }">
                                <svg class="field-icon" viewBox="0 0 20 20" fill="none"><rect x="4" y="9" width="12" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 9V7a3 3 0 016 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                <input v-model="loginForm.password" type="password" autocomplete="current-password" placeholder="••••••••">
                            </div>
                            <span v-if="fieldErrors.password" class="field-error">{{ fieldErrors.password[0] }}</span>
                        </label>
                    </div>

                    <div v-if="error" class="alert alert--error">
                        <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.5v4M10 13.5h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        {{ error }}
                    </div>

                    <button class="btn btn--primary btn--lg" type="submit" :disabled="authLoading">
                        <span v-if="authLoading" class="btn-spinner"></span>
                        {{ authLoading ? 'Входим...' : 'Войти' }}
                    </button>
                </form>
            </div>
        </div>

        <!-- ░░ Workspace ░░ -->
        <div v-else class="workspace">

            <!-- Topbar -->
            <header class="topbar">
                <div class="topbar-brand">
                    <p class="topbar-logo">Y</p>
                    <span>Yandex Reviews</span>
                </div>
                <div class="topbar-user">
                    <div class="topbar-avatar">{{ avatarLetter(user?.name) }}</div>
                    <span class="topbar-name">{{ user?.name }}</span>
                    <button class="btn btn--ghost btn--sm" @click="logout">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M13 3h4v14h-4M9 14l4-4-4-4M13 10H3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Выйти
                    </button>
                </div>
            </header>

            <div class="page-body">

                <!-- URL settings card -->
                <section class="card url-card">
                    <div class="card-head">
                        <div class="card-head-icon">
                            <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        </div>
                        <div>
                            <h2>Источник данных</h2>
                            <p>Вставьте ссылку на организацию в Яндекс.Картах</p>
                        </div>
                    </div>
                    <form class="url-form" @submit.prevent="saveOrganization">
                        <div class="field-wrap url-field-wrap" :class="{ 'field-wrap--error': fieldErrors.url }">
                            <svg class="field-icon" viewBox="0 0 20 20" fill="none"><path d="M12.9 7.1a4.5 4.5 0 010 5.8M7.1 12.9a4.5 4.5 0 010-5.8M3 10a7 7 0 1114 0A7 7 0 013 10z" stroke="currentColor" stroke-width="1.5"/><path d="M10 3v14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M3 10h14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            <input
                                v-model="settingsForm.url"
                                type="url"
                                placeholder="https://yandex.ru/maps/org/название/..."
                                :disabled="saving"
                            >
                        </div>
                        <button class="btn btn--primary" type="submit" :disabled="saving">
                            <span v-if="saving" class="btn-spinner"></span>
                            <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10h12M11 5l5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ saving ? 'Загрузка...' : 'Обновить' }}
                        </button>
                    </form>
                    <span v-if="fieldErrors.url" class="field-error">{{ fieldErrors.url[0] }}</span>
                    <div v-if="error" class="alert alert--error mt-8">
                        <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.5v4M10 13.5h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        {{ error }}
                    </div>
                </section>

                <!-- Org summary -->
                <section v-if="organization" class="org-summary">
                    <div class="org-main-card card">
                        <div class="org-main-info">
                            <div class="org-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M3 21h18M5 21V7l7-4 7 4v14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><rect x="9" y="14" width="6" height="7" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
                            </div>
                            <div>
                                <h3>{{ organization.title || 'Без названия' }}</h3>
                                <p>{{ organization.address }}</p>
                            </div>
                        </div>
                        <a v-if="organization.canonical_url" :href="organization.canonical_url" target="_blank" rel="noopener" class="org-link">
                            Открыть на Яндекс.Картах →
                        </a>
                    </div>

                    <div class="stats-row">
                        <div class="stat-card card">
                            <div class="stat-value stat-rating">{{ organization.rating_value ?? '—' }}</div>
                            <div class="stat-stars">
                                <span v-for="(filled, i) in starsArray(Math.round(organization.rating_value))" :key="i" :class="['star', { filled }]">★</span>
                            </div>
                            <div class="stat-label">Средний рейтинг</div>
                        </div>
                        <div class="stat-card card">
                            <div class="stat-value">{{ organization.rating_count.toLocaleString() }}</div>
                            <div class="stat-label">Оценок</div>
                        </div>
                        <div class="stat-card card">
                            <div class="stat-value">{{ organization.review_count.toLocaleString() }}</div>
                            <div class="stat-label">Отзывов</div>
                        </div>
                    </div>
                </section>

                <!-- Reviews -->
                <section v-if="organization" class="reviews-section">
                    <div class="reviews-header">
                        <h2>Отзывы</h2>
                        <span class="reviews-count-badge">{{ reviewsMeta.total.toLocaleString() }}</span>
                    </div>

                    <!-- Skeletons -->
                    <div v-if="loadingReviews" class="review-list">
                        <div v-for="n in 5" :key="n" class="review-card review-card--skeleton">
                            <div class="sk-line sk-avatar"></div>
                            <div class="sk-body">
                                <div class="sk-line sk-title"></div>
                                <div class="sk-line sk-text"></div>
                                <div class="sk-line sk-text sk-short"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Reviews list -->
                    <div v-else-if="reviews.length" class="review-list">
                        <article v-for="review in reviews" :key="review.id" class="review-card">
                            <div class="review-avatar" :style="{ background: avatarColor(review.author) }">
                                {{ avatarLetter(review.author) }}
                            </div>
                            <div class="review-body">
                                <div class="review-meta">
                                    <span class="review-author">{{ review.author || 'Автор скрыт' }}</span>
                                    <span class="review-date">{{ review.date || '' }}</span>
                                </div>
                                <div class="review-stars" v-if="review.rating">
                                    <span v-for="(f, i) in starsArray(review.rating)" :key="i" :class="['star', { filled: f }]">★</span>
                                </div>
                                <p class="review-text">{{ review.text || 'Без текста' }}</p>
                            </div>
                        </article>
                    </div>

                    <div v-else class="empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none"><path d="M8 10h8M8 14h5M5 3h14a2 2 0 012 2v12a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2z" stroke="#9ca3af" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        <p>Отзывов пока нет</p>
                    </div>

                    <!-- Pagination -->
                    <nav v-if="reviewsMeta.last_page > 1" class="pagination">
                        <button
                            class="pag-btn pag-arrow"
                            :disabled="reviewsMeta.current_page === 1 || loadingReviews"
                            @click="loadReviews(reviewsMeta.current_page - 1)"
                            title="Предыдущая"
                        >
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M13 5l-5 5 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <button
                            v-for="page in pages"
                            :key="page"
                            class="pag-btn"
                            :class="{ 'pag-btn--active': page === reviewsMeta.current_page }"
                            :disabled="loadingReviews"
                            @click="loadReviews(page)"
                        >
                            {{ page }}
                        </button>
                        <button
                            class="pag-btn pag-arrow"
                            :disabled="reviewsMeta.current_page === reviewsMeta.last_page || loadingReviews"
                            @click="loadReviews(reviewsMeta.current_page + 1)"
                            title="Следующая"
                        >
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </nav>
                </section>

            </div>
        </div>
    </div>
</template>
