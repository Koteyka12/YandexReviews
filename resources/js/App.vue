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
    email: 'test@example.com',
    password: '123321',
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

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
});

function messageFrom(errorResponse) {
    return errorResponse?.response?.data?.message || 'Запрос не выполнен.';
}

function setValidationErrors(errorResponse) {
    fieldErrors.value = errorResponse?.response?.data?.errors || {};
}

async function loadUser() {
    try {
        const response = await window.axios.get('/api/user');
        user.value = response.data.user;
    } catch {
        user.value = null;
    }
}

async function loadOrganization() {
    const response = await window.axios.get('/api/organization');
    organization.value = response.data.organization;

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
        const response = await window.axios.post('/api/login', loginForm);
        user.value = response.data.user;
        await loadOrganization();
    } catch (requestError) {
        setValidationErrors(requestError);
        error.value = messageFrom(requestError);
    } finally {
        authLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/api/logout');
    user.value = null;
    organization.value = null;
    reviews.value = [];
}

async function saveOrganization() {
    saving.value = true;
    error.value = '';
    fieldErrors.value = {};

    try {
        const response = await window.axios.post('/api/organization', {
            url: settingsForm.url,
        });
        organization.value = response.data.organization;
        await loadReviews(1);
    } catch (requestError) {
        setValidationErrors(requestError);
        error.value = messageFrom(requestError);
    } finally {
        saving.value = false;
    }
}

async function loadReviews(page) {
    if (!organization.value) {
        return;
    }

    loadingReviews.value = true;
    error.value = '';

    try {
        const response = await window.axios.get(`/api/organizations/${organization.value.id}/reviews`, {
            params: {
                page,
                per_page: 50,
            },
        });

        reviews.value = response.data.data;
        Object.assign(reviewsMeta, response.data.meta);
    } catch (requestError) {
        error.value = messageFrom(requestError);
    } finally {
        loadingReviews.value = false;
    }
}

onMounted(async () => {
    await loadUser();

    if (user.value) {
        await loadOrganization();
    }

    booting.value = false;
});
</script>

<template>
    <main class="app-shell">
        <section v-if="booting" class="center-screen">
            <div class="loader"></div>
        </section>

        <section v-else-if="!isAuthed" class="auth-screen">
            <form class="auth-panel" @submit.prevent="login">
                <div>
                    <p class="eyebrow">Yandex Reviews Parser</p>
                    <h1>Вход</h1>
                </div>

                <label>
                    <span>Email</span>
                    <input v-model="loginForm.email" type="email" autocomplete="username">
                    <small v-if="fieldErrors.email">{{ fieldErrors.email[0] }}</small>
                </label>

                <label>
                    <span>Пароль</span>
                    <input v-model="loginForm.password" type="password" autocomplete="current-password">
                    <small v-if="fieldErrors.password">{{ fieldErrors.password[0] }}</small>
                </label>

                <p v-if="error" class="error-line">{{ error }}</p>

                <button class="primary-button" type="submit" :disabled="authLoading">
                    {{ authLoading ? 'Вход...' : 'Войти' }}
                </button>
            </form>
        </section>

        <section v-else class="workspace">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Yandex Reviews Parser</p>
                    <h1>Отзывы организации</h1>
                </div>
                <button class="ghost-button" type="button" @click="logout">Выйти</button>
            </header>

            <section class="settings-band">
                <form class="settings-form" @submit.prevent="saveOrganization">
                    <label>
                        <span>Ссылка на карточку Яндекс.Карт</span>
                        <input
                            v-model="settingsForm.url"
                            type="url"
                            placeholder="https://yandex.ru/maps/org/..."
                            :disabled="saving"
                        >
                        <small v-if="fieldErrors.url">{{ fieldErrors.url[0] }}</small>
                    </label>
                    <button class="primary-button" type="submit" :disabled="saving">
                        {{ saving ? 'Парсинг...' : 'Сохранить' }}
                    </button>
                </form>
                <p v-if="error" class="error-line">{{ error }}</p>
            </section>

            <section v-if="organization" class="summary-grid">
                <div class="summary-item wide">
                    <span>Организация</span>
                    <strong>{{ organization.title || 'Без названия' }}</strong>
                    <em>{{ organization.address }}</em>
                </div>
                <div class="summary-item">
                    <span>Средний рейтинг</span>
                    <strong>{{ organization.rating_value ?? '—' }}</strong>
                </div>
                <div class="summary-item">
                    <span>Оценок</span>
                    <strong>{{ organization.rating_count }}</strong>
                </div>
                <div class="summary-item">
                    <span>Отзывов</span>
                    <strong>{{ organization.review_count }}</strong>
                </div>
                <div class="summary-item">
                    <span>Сохранено</span>
                    <strong>{{ organization.scraped_reviews_count }}</strong>
                </div>
            </section>

            <section v-if="organization" class="reviews-section">
                <div class="section-heading">
                    <h2>Отзывы</h2>
                    <span>{{ reviewsMeta.total }} записей</span>
                </div>

                <div v-if="loadingReviews" class="review-list muted-list">
                    <div class="review-card skeleton" v-for="item in 4" :key="item"></div>
                </div>

                <div v-else-if="reviews.length" class="review-list">
                    <article v-for="review in reviews" :key="review.id" class="review-card">
                        <div class="review-head">
                            <strong>{{ review.author || 'Автор скрыт' }}</strong>
                            <span>{{ review.date || 'Без даты' }}</span>
                        </div>
                        <p>{{ review.text || 'Без текста' }}</p>
                        <div class="rating-chip">{{ review.rating ?? '—' }} / 5</div>
                    </article>
                </div>

                <div v-else class="empty-state">Отзывов нет.</div>

                <nav v-if="reviewsMeta.last_page > 1" class="pagination">
                    <button
                        type="button"
                        :disabled="reviewsMeta.current_page === 1 || loadingReviews"
                        @click="loadReviews(reviewsMeta.current_page - 1)"
                    >
                        Назад
                    </button>
                    <button
                        v-for="page in pages"
                        :key="page"
                        type="button"
                        :class="{ active: page === reviewsMeta.current_page }"
                        :disabled="loadingReviews"
                        @click="loadReviews(page)"
                    >
                        {{ page }}
                    </button>
                    <button
                        type="button"
                        :disabled="reviewsMeta.current_page === reviewsMeta.last_page || loadingReviews"
                        @click="loadReviews(reviewsMeta.current_page + 1)"
                    >
                        Далее
                    </button>
                </nav>
            </section>
        </section>
    </main>
</template>
