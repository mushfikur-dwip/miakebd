<template>
    <div class="db-card">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ $t("menu.mobile_section") }}</h3>
        </div>
        <div class="db-card-body">
            <!-- Background Image Section -->
            <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                <h5 class="font-semibold mb-3">{{ $t("label.background_image") }}</h5>
                <form @submit.prevent="saveBackground" class="flex items-center gap-3 flex-wrap">
                    <input
                        type="file"
                        ref="fileInput"
                        @change="handleFileChange"
                        class="db-field-control"
                        accept="image/*"
                    />
                    <button
                        type="submit"
                        class="db-btn py-2 text-white bg-primary"
                        :disabled="loading || !imageFile"
                    >
                        <i class="lab lab-fill-save"></i>
                        <span>{{ $t("label.save") }}</span>
                    </button>
                    <img
                        v-if="background"
                        :src="background"
                        alt="Background"
                        class="h-12 w-24 object-cover rounded border-2 border-gray-200"
                    />
                </form>
            </div>

            <!-- Buttons Section -->
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h5 class="font-semibold">{{ $t("label.buttons") }}</h5>
                    <button class="db-btn py-2 text-white bg-primary" @click="openAddModal">
                        <i class="lab lab-fill-add"></i>
                        <span>{{ $t("label.add_button") }}</span>
                    </button>
                </div>

                <div class="db-table-responsive">
                    <table class="db-table stripe">
                        <thead class="db-table-head">
                            <tr class="db-table-head-tr">
                                <th class="db-table-head-th">{{ $t("label.name") }}</th>
                                <th class="db-table-head-th">{{ $t("label.url") }}</th>
                                <th class="db-table-head-th text-center">{{ $t("label.action") }}</th>
                            </tr>
                        </thead>
                        <tbody class="db-table-body" v-if="buttons.length > 0">
                            <tr class="db-table-body-tr" v-for="button in buttons" :key="button.id">
                                <td class="db-table-body-td">{{ button.name }}</td>
                                <td class="db-table-body-td">{{ button.url }}</td>
                                <td class="db-table-body-td text-center">
                                    <div class="flex justify-center gap-2">
                                        <button
                                            class="db-btn-outline blue py-1 px-3"
                                            @click="editButton(button)"
                                        >
                                            <i class="lab lab-fill-edit"></i>
                                            <span>{{ $t("label.edit") }}</span>
                                        </button>
                                        <button
                                            class="db-btn-outline red py-1 px-3"
                                            @click="deleteButton(button.id)"
                                        >
                                            <i class="lab lab-fill-delete"></i>
                                            <span>{{ $t("label.delete") }}</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal fade" id="buttonModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ isEdit ? $t("label.edit_button") : $t("label.add_button") }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form @submit.prevent="saveButton">
                    <div class="modal-body">
                        <!-- Link Type Selection -->
                        <div class="mb-3">
                            <label class="db-field-title">{{ $t("label.link_type") }}</label>
                            <div class="flex gap-4 mt-2">
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        v-model="linkType"
                                        value="custom"
                                        id="linkCustom"
                                    />
                                    <label class="form-check-label" for="linkCustom">
                                        {{ $t("label.custom_url") }}
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        v-model="linkType"
                                        value="category"
                                        id="linkCategory"
                                    />
                                    <label class="form-check-label" for="linkCategory">
                                        {{ $t("label.category") }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Category Dropdown -->
                        <div class="mb-3" v-if="linkType === 'category'">
                            <label class="db-field-title required">{{ $t("label.select_category") }}</label>
                            <select
                                class="db-field-control"
                                v-model="selectedCategory"
                                @change="onCategoryChange"
                            >
                                <option value="">{{ $t("label.select_category") }}</option>
                                <option
                                    v-for="cat in categories"
                                    :key="cat.id"
                                    :value="cat.slug"
                                >
                                    {{ cat.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Button Name -->
                        <div class="mb-3">
                            <label class="db-field-title required">{{ $t("label.button_name") }}</label>
                            <input
                                type="text"
                                class="db-field-control"
                                v-model="form.name"
                                :placeholder="$t('label.enter_button_name')"
                                required
                            />
                        </div>

                        <!-- Button URL -->
                        <div class="mb-3">
                            <label class="db-field-title required">{{ $t("label.button_url") }}</label>
                            <input
                                type="text"
                                class="db-field-control"
                                v-model="form.url"
                                :placeholder="$t('label.enter_button_url')"
                                :readonly="linkType === 'category'"
                                required
                            />
                            <small class="text-muted" v-if="linkType === 'custom'">
                                {{ $t("label.example") }}: /product?price_max=500
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="modal-btn-outline modal-close" data-bs-dismiss="modal">
                            <i class="lab lab-fill-close-circle"></i>
                            <span>{{ $t("label.close") }}</span>
                        </button>
                        <button type="submit" class="db-btn py-2 text-white bg-primary">
                            <i class="lab lab-fill-save"></i>
                            <span>{{ $t("label.save") }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import { Modal } from "bootstrap";

export default {
    name: "MobileSectionListComponent",
    data() {
        return {
            loading: false,
            buttons: [],
            categories: [],
            linkType: "custom",
            selectedCategory: "",
            background: null,
            imageFile: null,
            form: {
                id: null,
                name: "",
                url: "",
            },
            isEdit: false,
            modal: null,
        };
    },
    mounted() {
        this.fetchData();
        this.fetchCategories();
        const modalElement = document.getElementById("buttonModal");
        if (modalElement) {
            this.modal = new Modal(modalElement);
        }
    },
    methods: {
        fetchCategories() {
            axios
                .get("admin/setting/product-category", {
                    params: { paginate: 0 },
                })
                .then((res) => {
                    this.categories = res.data.data || [];
                })
                .catch((err) => {
                    console.error("Failed to fetch categories:", err);
                    window.alertify && window.alertify.error("Failed to load categories");
                });
        },
        onCategoryChange() {
            if (!this.selectedCategory) {
                this.form.name = "";
                this.form.url = "";
                return;
            }
            const cat = this.categories.find(
                (c) => c.slug === this.selectedCategory
            );
            if (cat) {
                this.form.name = cat.name;
                this.form.url = `/product?category=${cat.slug}`;
            }
        },
        fetchData() {
            axios
                .get("admin/setting/mobile-section")
                .then((res) => {
                    this.buttons = res.data.data.buttons || [];
                    this.background = res.data.data.background || null;
                })
                .catch((err) => {
                    console.error("Failed to fetch data:", err);
                    window.alertify && window.alertify.error("Failed to load mobile section data");
                });
        },
        handleFileChange(e) {
            this.imageFile = e.target.files[0];
        },
        saveBackground(e) {
            if (!this.imageFile) {
                window.alertify && window.alertify.error("Please select an image");
                return;
            }
            let formData = new FormData();
            formData.append("image", this.imageFile);

            this.loading = true;
            axios
                .post("admin/setting/mobile-section/background", formData, {
                    headers: {
                        "Content-Type": "multipart/form-data",
                    },
                })
                .then((res) => {
                    this.fetchData();
                    this.loading = false;
                    this.imageFile = null;
                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.value = "";
                    }
                    window.alertify && window.alertify.success(res.data.message || "Background updated successfully");
                })
                .catch((err) => {
                    this.loading = false;
                    console.error("Background save error:", err);
                    window.alertify && window.alertify.error(
                        err.response?.data?.message || "Failed to save background"
                    );
                });
        },
        openAddModal() {
            this.form = { id: null, name: "", url: "" };
            this.linkType = "custom";
            this.selectedCategory = "";
            this.isEdit = false;
            if (this.modal) {
                this.modal.show();
                // Fix modal visibility issue
                setTimeout(() => {
                    const modalElement = document.getElementById("buttonModal");
                    if (modalElement) {
                        modalElement.style.display = 'block';
                        modalElement.style.opacity = '1';
                        modalElement.style.visibility = 'visible';
                    }
                }, 50);
            }
        },
        editButton(button) {
            this.form = { ...button };
            this.linkType = "custom";
            this.selectedCategory = "";
            this.isEdit = true;
            if (this.modal) {
                this.modal.show();
                // Fix modal visibility issue
                setTimeout(() => {
                    const modalElement = document.getElementById("buttonModal");
                    if (modalElement) {
                        modalElement.style.display = 'block';
                        modalElement.style.opacity = '1';
                        modalElement.style.visibility = 'visible';
                    }
                }, 50);
            }
        },
        saveButton() {
            if (!this.form.name || !this.form.url) {
                window.alertify && window.alertify.error("Please fill all required fields");
                return;
            }

            const url = this.isEdit
                ? `admin/setting/mobile-section/button/${this.form.id}`
                : "admin/setting/mobile-section/button";
            const method = this.isEdit ? "put" : "post";

            // Debug: Log the data being sent
            console.log('Sending data:', {
                name: this.form.name,
                url: this.form.url,
                method: method,
                endpoint: url
            });

            axios[method](url, {
                name: this.form.name,
                url: this.form.url
            })
                .then((res) => {
                    this.fetchData();
                    if (this.modal) {
                        this.modal.hide();
                    }
                    window.alertify && window.alertify.success(
                        res.data.message ||
                            (this.isEdit
                                ? "Button updated successfully"
                                : "Button added successfully")
                    );
                })
                .catch((err) => {
                    console.error("Save button error:", err);
                    console.error("Error response:", err.response?.data);
                    console.error("Error status:", err.response?.status);
                    console.error("Error headers:", err.response?.headers);
                    
                    let errorMessage = "Failed to save button";
                    if (err.response?.data?.message) {
                        errorMessage = err.response.data.message;
                    } else if (err.response?.data?.errors) {
                        const errors = Object.values(err.response.data.errors).flat();
                        errorMessage = errors.join(', ');
                    }
                    
                    window.alertify && window.alertify.error(errorMessage);
                });
        },
        deleteButton(id) {
            if (!confirm("Are you sure you want to delete this button?")) return;

            axios
                .delete(`admin/setting/mobile-section/button/${id}`)
                .then((res) => {
                    this.fetchData();
                    window.alertify && window.alertify.success(res.data.message || "Button deleted successfully");
                })
                .catch((err) => {
                    console.error("Delete button error:", err);
                    window.alertify && window.alertify.error(
                        err.response?.data?.message || "Failed to delete button"
                    );
                });
        },
    },
};
</script>
