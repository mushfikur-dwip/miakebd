<template>
    <LoadingComponent :props="loading" />

    <div class="db-card">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("menu.customer_message") }}</h3>
        </div>

        <div class="db-card-body">
            <div class="form-row">
                <div class="form-col-12">
                    <label for="title" class="db-field-title">{{ $t("label.title") }}</label>
                    <input v-model="form.title" type="text" id="title" class="db-field-control"
                        :placeholder="$t('label.campaign_title_placeholder')" />
                </div>

                <div class="form-col-12">
                    <label for="message" class="db-field-title required">{{ $t("label.message") }}</label>
                    <textarea v-model="form.message" id="message" rows="4" class="db-field-control"
                        v-bind:class="errors.message ? 'invalid' : ''"
                        :placeholder="$t('label.campaign_message_placeholder')"></textarea>
                    <small class="db-field-alert" v-if="errors.message">{{ errors.message[0] }}</small>
                    <p class="text-sm text-text mt-1">
                        {{ $t('label.campaign_placeholder_hint') }}
                    </p>
                </div>

                <!-- What the customer actually receives. The template is easy to
                     get wrong in a way that is invisible until it has already
                     been texted to everyone. -->
                <div class="form-col-12" v-if="form.message">
                    <label class="db-field-title">{{ $t("label.preview") }}</label>
                    <div class="p-3 rounded-lg bg-slate-100 text-sm whitespace-pre-line">{{ preview }}</div>
                    <p class="text-sm text-text mt-1">
                        {{ $t('label.characters') }}: {{ preview.length }} &middot;
                        {{ $t('label.sms_parts') }}: {{ smsParts }}
                    </p>
                </div>

                <div class="form-col-12 sm:form-col-6">
                    <label for="test_phone" class="db-field-title">{{ $t("label.send_test_sms") }}</label>
                    <div class="flex items-start gap-2">
                        <input v-model="test.country_code" type="text" class="db-field-control w-24"
                            placeholder="+880" />
                        <input v-model="test.phone" type="text" id="test_phone" class="db-field-control"
                            :placeholder="$t('label.phone')" />
                        <button type="button" @click="sendTest" :disabled="!canTest"
                            class="db-btn-primary py-2 px-4 rounded-lg whitespace-nowrap disabled:opacity-60">
                            {{ $t("button.send") }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-5 p-4 rounded-xl border border-gray-100">
                <p class="font-semibold">
                    {{ $t('label.recipients') }}:
                    <span v-if="audience === null">…</span>
                    <span v-else>{{ audience }}</span>
                </p>
                <p class="text-sm text-text mt-1">{{ $t('label.campaign_cost_hint') }}</p>

                <button v-if="!campaign" type="button" @click="confirmAndStart" :disabled="!canStart"
                    class="db-btn-primary py-2.5 px-6 rounded-lg mt-3 disabled:opacity-60">
                    {{ $t("button.send_to_all") }}
                </button>
            </div>

            <!-- Progress. Batches are driven from here because the server has no
                 queue worker; closing this tab only pauses the run. -->
            <div v-if="campaign" class="mt-5 p-4 rounded-xl border border-gray-100">
                <div class="w-full h-3 rounded-full bg-slate-200 overflow-hidden">
                    <div class="h-full bg-primary transition-all duration-300" :style="{ width: percent + '%' }"></div>
                </div>

                <p class="mt-3 font-semibold">
                    {{ campaign.sent_count }} / {{ campaign.total_count }} {{ $t('label.sent') }}
                    <span v-if="campaign.failed_count > 0" class="text-red-500">
                        &middot; {{ campaign.failed_count }} {{ $t('label.failed') }}
                    </span>
                </p>

                <p v-if="campaign.is_finished" class="text-sm mt-1 text-green-600">
                    {{ $t('label.campaign_finished') }}
                </p>
                <p v-else-if="running" class="text-sm mt-1 text-text">{{ $t('label.campaign_running_hint') }}</p>
                <p v-else class="text-sm mt-1 text-text">{{ $t('label.campaign_paused_hint') }}</p>

                <div class="flex items-center gap-2 mt-3">
                    <button v-if="running" type="button" @click="stop"
                        class="db-btn py-2 px-5 rounded-lg border border-red-500 text-red-500">
                        {{ $t("button.stop") }}
                    </button>
                    <button v-else-if="!campaign.is_finished" type="button" @click="run"
                        class="db-btn-primary py-2 px-5 rounded-lg">
                        {{ $t("button.resume") }}
                    </button>
                    <button v-if="campaign.is_finished" type="button" @click="reset"
                        class="db-btn py-2 px-5 rounded-lg border border-gray-200">
                        {{ $t("button.new") }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="db-card mt-5" v-if="campaigns.length > 0">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("label.history") }}</h3>
        </div>
        <div class="db-table-responsive">
            <table class="db-table stripe">
                <thead class="db-table-head">
                    <tr class="db-table-head-tr">
                        <th class="db-table-head-th">{{ $t("label.title") }}</th>
                        <th class="db-table-head-th">{{ $t("label.message") }}</th>
                        <th class="db-table-head-th">{{ $t("label.sent") }}</th>
                        <th class="db-table-head-th">{{ $t("label.failed") }}</th>
                        <th class="db-table-head-th">{{ $t("label.date") }}</th>
                    </tr>
                </thead>
                <tbody class="db-table-body">
                    <tr class="db-table-body-tr" v-for="row in campaigns" :key="row.id">
                        <td class="db-table-body-td">{{ row.title || '-' }}</td>
                        <td class="db-table-body-td">{{ textShortener(row.message, 60) }}</td>
                        <td class="db-table-body-td">{{ row.sent_count }} / {{ row.total_count }}</td>
                        <td class="db-table-body-td">{{ row.failed_count }}</td>
                        <td class="db-table-body-td">{{ row.created_at }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";

export default {
    name: "CustomerMessageComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            form: { title: "", message: "" },
            test: { country_code: "", phone: "" },
            errors: {},
            audience: null,
            campaign: null,
            running: false,
            // Set when the operator presses Stop so the in-flight batch does not
            // schedule another one.
            cancelled: false,
        }
    },
    computed: {
        campaigns: function () {
            return this.$store.getters["smsCampaign/lists"];
        },
        preview: function () {
            return (this.form.message || "").split("{name}").join(this.$t("label.customer"));
        },
        // Rough guide only - the gateway decides. Non-GSM characters (Bangla)
        // are billed in 70-character parts instead of 160.
        smsParts: function () {
            const text = this.preview;
            if (!text) return 0;
            const unicode = /[^ -]/.test(text);
            const size = unicode ? 70 : 160;
            return Math.ceil(text.length / size);
        },
        canStart: function () {
            return !!this.form.message.trim() && this.audience > 0 && !this.running;
        },
        canTest: function () {
            return !!this.form.message.trim() && !!this.test.phone.trim();
        },
        percent: function () {
            if (!this.campaign || !this.campaign.total_count) return 0;
            const done = this.campaign.sent_count + this.campaign.failed_count;
            return Math.min(100, Math.round((done / this.campaign.total_count) * 100));
        },
    },
    mounted() {
        this.loadAudience();
        this.$store.dispatch("smsCampaign/lists").then().catch();
    },
    methods: {
        textShortener(text, length) {
            return appService.textShortener(text, length);
        },
        loadAudience: function () {
            this.$store.dispatch("smsCampaign/audience").then((res) => {
                this.audience = res.data.total;
            }).catch(() => {
                this.audience = 0;
            });
        },
        sendTest: function () {
            this.loading.isActive = true;
            this.$store.dispatch("smsCampaign/test", {
                country_code: this.test.country_code,
                phone: this.test.phone,
                message: this.form.message,
                name: this.$t("label.customer"),
            }).then(() => {
                this.loading.isActive = false;
                alertService.success(this.$t("label.send_test_sms"));
            }).catch((err) => {
                this.loading.isActive = false;
                alertService.error(err.response?.data?.message || this.$t("message.something_went_wrong"));
            });
        },
        /**
         * Confirms first. This spends real money on every recipient and cannot
         * be recalled once a batch has left, so the count is repeated back.
         */
        confirmAndStart: function () {
            const ok = window.confirm(
                this.$t("label.campaign_confirm", { count: this.audience })
            );

            if (!ok) {
                return;
            }

            this.errors = {};
            this.loading.isActive = true;

            this.$store.dispatch("smsCampaign/save", this.form).then((res) => {
                this.loading.isActive = false;
                this.campaign = res.data.data;
                this.run();
            }).catch((err) => {
                this.loading.isActive = false;
                this.errors = err.response?.data?.errors || {};
                if (!Object.keys(this.errors).length) {
                    alertService.error(err.response?.data?.message || this.$t("message.something_went_wrong"));
                }
            });
        },
        /** Sends batch after batch until the list is done or Stop is pressed. */
        run: function () {
            if (!this.campaign || this.campaign.is_finished) {
                return;
            }

            this.running = true;
            this.cancelled = false;
            this.nextBatch();
        },
        nextBatch: function () {
            if (this.cancelled || !this.campaign || this.campaign.is_finished) {
                this.running = false;
                return;
            }

            this.$store.dispatch("smsCampaign/batch", this.campaign.id).then((res) => {
                this.campaign = res.data.data;

                if (this.campaign.is_finished) {
                    this.running = false;
                    this.$store.dispatch("smsCampaign/lists").then().catch();
                    alertService.success(this.$t("label.campaign_finished"));
                    return;
                }

                this.nextBatch();
            }).catch((err) => {
                // Stop on error rather than hammering a failing gateway. What
                // was already sent stays recorded, so Resume picks up after it.
                this.running = false;
                alertService.error(err.response?.data?.message || this.$t("message.something_went_wrong"));
            });
        },
        stop: function () {
            this.cancelled = true;
            this.running = false;
            this.$store.dispatch("smsCampaign/pause", this.campaign.id).then((res) => {
                this.campaign = res.data.data;
            }).catch(() => { });
        },
        reset: function () {
            this.campaign = null;
            this.running = false;
            this.cancelled = false;
            this.form = { title: "", message: "" };
            this.errors = {};
            this.loadAudience();
        },
    },
}
</script>
