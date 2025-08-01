<template>

    <div>
        <breadcrumb v-if="breadcrumbs" :url="breadcrumbs[1].url" :title="breadcrumbs[1].text" />

        <div class="flex items-baseline mb-6">
            <h1 class="flex-1 self-start rtl:ml-4 ltr:mr-4">
                <div class="flex items-baseline">
                    <span v-if="! isCreating" class="little-dot rtl:ml-2 ltr:mr-2 -top-1" :class="activeLocalization.status" v-tooltip="__(activeLocalization.status)" />
                    <span class="break-overflowing-words" v-html="$options.filters.striptags(__(title))" />
                </div>
            </h1>

            <dropdown-list class="rtl:ml-4 ltr:mr-4" v-if="canEditBlueprint || hasItemActions">
                <dropdown-item :text="__('Edit Blueprint')" v-if="canEditBlueprint" :redirect="actions.editBlueprint" />
                <li class="divider" />
                <data-list-inline-actions
                    v-if="!isCreating && hasItemActions"
                    :item="values.id"
                    :url="itemActionUrl"
                    :actions="itemActions"
                    :is-dirty="isDirty"
                    @started="actionStarted"
                    @completed="actionCompleted"
                />
            </dropdown-list>

            <div class="pt-px text-2xs text-gray-600 flex rtl:ml-4 ltr:mr-4" v-if="readOnly">
                <svg-icon name="light/lock" class="w-4 rtl:ml-1 ltr:mr-1 -mt-1" /> {{ __('Read Only') }}
            </div>

            <div class="hidden md:flex items-center">
                <save-button-options
                    v-if="!readOnly"
                    :show-options="!revisionsEnabled && !isInline"
                    :button-class="saveButtonClass"
                    :preferences-prefix="preferencesPrefix"
                >
                    <button
                        :class="saveButtonClass"
                        :disabled="!canSave"
                        @click.prevent="save"
                        v-text="saveText"
                    />
                </save-button-options>

                <save-button-options
                    v-if="revisionsEnabled && !isCreating"
                    :show-options="!isInline"
                    button-class="btn-primary"
                    :preferences-prefix="preferencesPrefix"
                >
                    <button
                        class="rtl:mr-4 ltr:ml-4 btn-primary flex items-center"
                        :disabled="!canPublish"
                        @click="confirmingPublish = true"
                        v-text="publishButtonText"
                    />
                </save-button-options>
            </div>

            <slot name="action-buttons-right" />
        </div>

        <publish-container
            v-if="fieldset"
            ref="container"
            :name="publishContainer"
            :blueprint="fieldset"
            :values="values"
            :extra-values="extraValues"
            :reference="initialReference"
            :meta="meta"
            :errors="errors"
            :site="site"
            :localized-fields="localizedFields"
            :is-root="isRoot"
            :track-dirty-state="trackDirtyState"
            @updated="values = $event"
        >
            <live-preview
                slot-scope="{ container, components, setFieldMeta }"
                :name="publishContainer"
                :url="livePreviewUrl"
                :previewing="isPreviewing"
                :targets="previewTargets"
                :values="values"
                :blueprint="fieldset.handle"
                :reference="initialReference"
                @opened-via-keyboard="openLivePreview"
                @closed="closeLivePreview"
            >

                <div>
                    <component
                        v-for="component in components"
                        :key="component.id"
                        :is="component.name"
                        :container="container"
                        v-bind="component.props"
                        v-on="component.events"
                    />

                    <transition name="live-preview-tabs-drop">
                        <publish-tabs
                            v-show="tabsVisible"
                            :read-only="readOnly"
                            :syncable="hasOrigin"
                            @updated="setFieldValue"
                            @meta-updated="setFieldMeta"
                            @synced="syncField"
                            @desynced="desyncField"
                            @focus="container.$emit('focus', $event)"
                            @blur="container.$emit('blur', $event)"
                        >
                            <template #actions="{ shouldShowSidebar }">
                            <div class="card p-0 mb-5">

                                <div v-if="collectionHasRoutes" :class="{ 'hi': !shouldShowSidebar }">

                                    <div class="p-3 flex flex-wrap items-center gap-2 rtl:space-x-reverse" v-if="showLivePreviewButton || showVisitUrlButton">
                                        <button
                                            class="flex flex-1 items-center justify-center btn px-2"
                                            v-if="showLivePreviewButton"
                                            @click="openLivePreview">
                                            <svg-icon name="light/synchronize" class="h-4 w-4 rtl:ml-2 ltr:mr-2 shrink-0" />
                                            <span>{{ __('Live Preview') }}</span>
                                        </button>
                                        <a
                                            class="flex flex-1 items-center justify-center btn px-2"
                                            v-if="showVisitUrlButton"
                                            :href="permalink"
                                            target="_blank">
                                            <svg-icon name="light/external-link" class="w-4 h-4 rtl:ml-2 ltr:mr-2 shrink-0" />
                                            <span>{{ __('Visit URL') }}</span>
                                        </a>
                                    </div>
                                </div>

                                <div
                                    v-if="!revisionsEnabled"
                                    class="flex items-center justify-between px-4 py-2"
                                    :class="{ 'border-t dark:border-dark-900': showLivePreviewButton || showVisitUrlButton }"
                                >
                                    <label v-text="__('Published')" class="publish-field-label font-medium" />
                                    <toggle-input :value="published" :read-only="!canManagePublishState" @input="setFieldValue('published', $event)" />
                                </div>

                                <div
                                    v-if="revisionsEnabled && !isCreating"
                                    class="p-4"
                                    :class="{ 'border-t dark:border-dark-900': showLivePreviewButton || showVisitUrlButton }"
                                >
                                    <label class="publish-field-label font-medium mb-2" v-text="__('Revisions')"/>
                                    <div class="mb-1 flex items-center" v-if="published">
                                        <span class="text-green-600 w-6 text-center">&check;</span>
                                        <span class="text-2xs" v-text="__('Entry has a published version')"></span>
                                    </div>
                                    <div class="mb-1 flex items-center" v-else>
                                        <span class="text-orange w-6 text-center">!</span>
                                        <span class="text-2xs" v-text="__('Entry has not been published')"></span>
                                    </div>
                                    <div class="mb-1 flex items-center" v-if="!isWorkingCopy && published">
                                        <span class="text-green-600 w-6 text-center">&check;</span>
                                        <span class="text-2xs" v-text="__('This is the published version')"></span>
                                    </div>
                                    <div class="mb-1 flex items-center" v-if="isDirty">
                                        <span class="text-orange w-6 text-center">!</span>
                                        <span class="text-2xs" v-text="__('Unsaved changes')"></span>
                                    </div>
                                    <button
                                            class="flex items-center justify-center mt-4 btn-flat px-2 w-full"
                                            v-if="!isCreating && revisionsEnabled"
                                            @click="showRevisionHistory = true">
                                            <svg-icon name="light/history" class="h-4 w-4 rtl:ml-2 ltr:mr-2" />
                                            <span>{{ __('View History') }}</span>
                                        </button>
                                </div>

                                <div class="p-4 border-t dark:border-dark-900" v-if="localizations.length > 1">
                                    <label class="publish-field-label font-medium mb-2" v-text="__('Sites')" />
                                    <div
                                        v-for="option in localizations"
                                        :key="option.handle"
                                        class="text-sm flex items-center -mx-4 px-4 py-2"
                                        :class="[
                                            option.active ? 'bg-blue-100 dark:bg-dark-300' : 'hover:bg-gray-200 dark:hover:bg-dark-400',
                                            !canSave && !option.exists ? 'cursor-not-allowed' : 'cursor-pointer',
                                        ]"
                                        @click="localizationSelected(option)"
                                    >
                                        <div class="flex-1 flex items-center" :class="{ 'line-through': !option.exists }">
                                            <span class="little-dot rtl:ml-2 ltr:mr-2" :class="{
                                                'bg-green-600': option.published,
                                                'bg-gray-500': !option.published,
                                                'bg-red-500': !option.exists
                                            }" />
                                            {{ __(option.name) }}
                                            <loading-graphic
                                                :size="14"
                                                text=""
                                                class="rtl:mr-2 ltr:ml-2"
                                                v-if="localizing && localizing.handle === option.handle" />
                                        </div>
                                        <div class="badge-sm bg-orange dark:bg-orange-dark" v-if="option.origin" v-text="__('Origin')" />
                                        <div class="badge-sm bg-blue dark:bg-dark-blue-175" v-if="option.active" v-text="__('Active')" />
                                        <div class="badge-sm bg-purple dark:bg-purple-dark" v-if="option.root && !option.origin && !option.active" v-text="__('Root')" />
                                    </div>
                                </div>
                            </div>
                            </template>
                        </publish-tabs>
                    </transition>
                </div>
                <template v-slot:buttons>
                   <button
                        v-if="!readOnly"
                        class="rtl:mr-4 ltr:ml-4"
                        :class="{
                            'btn': revisionsEnabled,
                            'btn-primary': isCreating || !revisionsEnabled,
                        }"
                        :disabled="!canSave"
                        @click.prevent="save"
                        v-text="saveText">
                    </button>

                    <button
                        v-if="revisionsEnabled && !isCreating"
                        class="rtl:mr-4 ltr:ml-4 btn-primary flex items-center"
                        :disabled="!canPublish"
                        @click="confirmingPublish = true">
                        <span v-text="publishButtonText" />
                        <svg-icon name="micro/chevron-down-xs" class="rtl:mr-2 ltr:ml-2 w-2" />
                    </button>
                </template>
            </live-preview>
        </publish-container>

        <div class="md:hidden mt-6 flex items-center">
            <button
                v-if="!readOnly"
                class="btn-lg"
                :class="{
                    'btn-primary w-full': ! revisionsEnabled,
                    'btn w-1/2 rtl:ml-4 ltr:mr-4': revisionsEnabled,
                }"
                :disabled="!canSave"
                @click.prevent="save"
                v-text="__(revisionsEnabled ? 'Save Changes' : 'Save')" />

            <button
                v-if="revisionsEnabled"
                class="rtl:mr-2 ltr:ml-2 btn btn-lg justify-center btn-primary flex items-center w-1/2"
                :disabled="!canPublish"
                @click="confirmingPublish = true">
                <span v-text="publishButtonText" />
                <svg-icon name="micro/chevron-down-xs" class="rtl:mr-2 ltr:ml-2 w-2" />
            </button>
        </div>

        <stack name="revision-history" v-if="showRevisionHistory" @closed="showRevisionHistory = false" :narrow="true">
            <revision-history
                slot-scope="{ close }"
                :index-url="actions.revisions"
                :restore-url="actions.restore"
                :reference="initialReference"
                :can-restore-revisions="!readOnly"
                @closed="close"
            />
        </stack>

        <publish-actions
            v-if="confirmingPublish"
            :actions="actions"
            :published="published"
            :collection="collectionHandle"
            :reference="initialReference"
            :publish-container="publishContainer"
            :can-manage-publish-state="canManagePublishState"
            @closed="confirmingPublish = false"
            @saving="saving = true"
            @saved="publishActionCompleted"
            @failed="publishActionFailed"
        />

        <confirmation-modal
            v-if="selectingOrigin"
            :title="__('Create Localization')"
            :buttonText="__('Create')"
            @cancel="cancelLocalization()"
            @confirm="createLocalization(localizing)"
        >
            <div class="publish-fields">
                <div class="form-group publish-field field-w-full">
                    <label v-text="__('Origin')" />
                    <div class="help-block -mt-2" v-text="__('messages.entry_origin_instructions')"></div>
                    <select-input
                        v-model="selectedOrigin"
                        :options="originOptions"
                        :placeholder="false"
                    />
                </div>
            </div>

        </confirmation-modal>
    </div>

</template>


<script>
import PublishActions from './PublishActions.vue';
import SaveButtonOptions from '../publish/SaveButtonOptions.vue';
import RevisionHistory from '../revision-history/History.vue';
import HasPreferences from '../data-list/HasPreferences';
import HasHiddenFields from '../publish/HasHiddenFields';
import HasActions from '../publish/HasActions';

export default {

    mixins: [
        HasPreferences,
        HasHiddenFields,
        HasActions,
    ],

    components: {
        PublishActions,
        SaveButtonOptions,
        RevisionHistory,
    },

    props: {
        publishContainer: String,
        initialReference: String,
        initialFieldset: Object,
        initialValues: Object,
        initialExtraValues: Object,
        initialMeta: Object,
        initialTitle: String,
        initialLocalizations: Array,
        initialLocalizedFields: Array,
        originBehavior: String,
        initialHasOrigin: Boolean,
        initialOriginValues: Object,
        initialOriginMeta: Object,
        initialSite: String,
        initialIsWorkingCopy: Boolean,
        collectionHandle: String,
        breadcrumbs: Array,
        initialActions: Object,
        method: String,
        isCreating: Boolean,
        isInline: Boolean,
        initialReadOnly: Boolean,
        initialIsRoot: Boolean,
        initialPermalink: String,
        revisionsEnabled: Boolean,
        preloadedAssets: Array,
        canEditBlueprint: Boolean,
        canManagePublishState: Boolean,
        createAnotherUrl: String,
        initialListingUrl: String,
        collectionHasRoutes: Boolean,
        previewTargets: Array,
        autosaveInterval: Number,
    },

    data() {
        return {
            actions: this.initialActions,
            saving: false,
            localizing: false,
            trackDirtyState: true,
            fieldset: this.initialFieldset,
            title: this.initialTitle,
            values: _.clone(this.initialValues),
            meta: _.clone(this.initialMeta),
            extraValues: _.clone(this.initialExtraValues),
            localizations: _.clone(this.initialLocalizations),
            localizedFields: this.initialLocalizedFields,
            hasOrigin: this.initialHasOrigin,
            originValues: this.initialOriginValues || {},
            originMeta: this.initialOriginMeta || {},
            site: this.initialSite,
            selectingOrigin: false,
            selectedOrigin: null,
            isWorkingCopy: this.initialIsWorkingCopy,
            error: null,
            errors: {},
            isPreviewing: false,
            tabsVisible: true,
            state: 'new',
            revisionMessage: null,
            showRevisionHistory: false,
            preferencesPrefix: `collections.${this.collectionHandle}`,

            // Whether it was published the last time it was saved.
            // Successful publish actions (if using revisions) or just saving (if not) will update this.
            // The current published value is inside the "values" object, and also accessible as a computed.
            initialPublished: this.initialValues.published,

            confirmingPublish: false,
            readOnly: this.initialReadOnly,
            isRoot: this.initialIsRoot,
            permalink: this.initialPermalink,

            saveKeyBinding: null,
            quickSaveKeyBinding: null,
            quickSave: false,
            isAutosave: false,
        }
    },

    computed: {

        hasErrors() {
            return this.error || Object.keys(this.errors).length;
        },

        somethingIsLoading() {
            return ! this.$progress.isComplete();
        },

        canSave() {
            return !this.readOnly && !this.somethingIsLoading;
        },

        canPublish() {
            if (!this.revisionsEnabled) return false;

            if (this.readOnly || this.isCreating || this.somethingIsLoading || this.isDirty) return false;

            return true;
        },

        published() {
            return this.values.published;
        },

        listingUrl() {
            return `${this.initialListingUrl}?site=${this.site}`;
        },

        livePreviewUrl() {
            return _.findWhere(this.localizations, { active: true }).livePreviewUrl;
        },

        showLivePreviewButton() {
            return !this.isCreating && this.isBase && this.livePreviewUrl;
        },

        showVisitUrlButton() {
            return !!this.permalink;
        },

        isBase() {
            return this.publishContainer === 'base';
        },

        isDirty() {
            return this.$dirty.has(this.publishContainer);
        },

        activeLocalization() {
            return _.findWhere(this.localizations, { active: true });
        },

        saveText() {
            switch(true) {
                case this.revisionsEnabled:
                    return __('Save Changes');
                case this.isUnpublishing:
                    return __('Save & Unpublish');
                case this.isDraft:
                    return __('Save Draft');
                default:
                    return __('Save & Publish');
            }
        },

        publishButtonText() {
            if (this.canManagePublishState) {
                return `${__('Publish')}…`
            }

            return `${__('Create Revision')}…`
        },

        isUnpublishing() {
            return this.initialPublished && ! this.published && ! this.isCreating;
        },

        isDraft() {
            return ! this.published;
        },

        saveButtonClass() {
            return {
                'btn': this.revisionsEnabled,
                'btn-primary': this.isCreating || ! this.revisionsEnabled,
            };
        },

        afterSaveOption() {
            return this.getPreference('after_save');
        },

        originOptions() {
            return this.localizations
                .filter(localization => localization.exists)
                .map(localization => ({
                    value: localization.handle,
                    label: localization.name,
                }));
        },

        direction() {
            return this.$config.get('direction', 'ltr');
        },

    },

    watch: {

        saving(saving) {
            this.$progress.loading(`${this.publishContainer}-entry-publish-form`, saving);
        },

        title(title) {
            if (this.isBase) {
                const arrow = this.direction === 'ltr' ? '‹' : '›';
                document.title = `${title} ${arrow} ${this.breadcrumbs[1].text} ${arrow} ${this.breadcrumbs[0].text} ${arrow} ${__('Statamic')}`;
            }
        },

    },

    methods: {

        clearErrors() {
            this.error = null;
            this.errors = {};
        },

        save() {
            if (! this.canSave) {
                this.quickSave = false;
                return;
            }

            this.saving = true;
            this.clearErrors();

            setTimeout(() => this.runBeforeSaveHook(), 151); // 150ms is the debounce time for fieldtype updates
        },

        runBeforeSaveHook() {
            this.$refs.container.saving();

            Statamic.$hooks.run('entry.saving', {
                collection: this.collectionHandle,
                values: this.values,
                container: this.$refs.container,
                storeName: this.publishContainer,
            })
            .then(this.performSaveRequest)
            .catch(error => {
                this.saving = false;
                this.$toast.error(error || 'Something went wrong');
            });
        },

        performSaveRequest() {
            // Once the hook has completed, we need to make the actual request.
            // We build the payload here because the before hook may have modified values.
            const payload = { ...this.visibleValues, ...{
                _blueprint: this.fieldset.handle,
                _localized: this.localizedFields,
            }};

            this.$axios[this.method](this.actions.save, payload).then(response => {
                this.saving = false;
                if (! response.data.saved) {
                    return this.$toast.error(__(`Couldn't save entry`));
                }
                this.title = response.data.data.title;
                this.isWorkingCopy = true;
                if (!this.revisionsEnabled) this.permalink = response.data.data.permalink;
                if (!this.isCreating && !this.isAutosave) this.$toast.success(__('Saved'));
                this.$refs.container.saved();
                this.runAfterSaveHook(response);
            }).catch(error => this.handleAxiosError(error));
        },

        runAfterSaveHook(response) {
            // Once the save request has completed, we want to run the "after" hook.
            // Devs can do what they need and we'll wait for them, but they can't cancel anything.
            Statamic.$hooks
                .run('entry.saved', {
                    collection: this.collectionHandle,
                    reference: this.initialReference,
                    response
                })
                .then(() => {
                    // If revisions are enabled, just emit event.
                    if (this.revisionsEnabled) {
                        clearTimeout(this.trackDirtyStateTimeout)
                        this.trackDirtyState = false
                        this.values = this.resetValuesFromResponse(response.data.data.values);
                        this.extraValues = response.data.data.extraValues;
                        this.trackDirtyStateTimeout = setTimeout(() => (this.trackDirtyState = true), 750)
                        this.$nextTick(() => this.$emit('saved', response));
                        return;
                    }

                    let nextAction = this.quickSave || this.isAutosave ? 'continue_editing' : this.afterSaveOption;

                    // If the user has opted to create another entry, redirect them to create page.
                    if (!this.isInline && nextAction === 'create_another') {
                        window.location = this.createAnotherUrl;
                    }

                    // If the user has opted to go to listing (default/null option), redirect them there.
                    else if (!this.isInline && nextAction === null) {
                        window.location = this.listingUrl;
                    }

                    // Otherwise, leave them on the edit form and emit an event. We need to wait until after
                    // the hooks are resolved because if this form is being shown in a stack, we only
                    // want to close it once everything's done.
                    else {
                        clearTimeout(this.trackDirtyStateTimeout);
                        this.trackDirtyState = false;
                        this.values = this.resetValuesFromResponse(response.data.data.values);
                        this.extraValues = response.data.data.extraValues;
                        this.trackDirtyStateTimeout = setTimeout(() => (this.trackDirtyState = true), 750);
                        this.initialPublished = response.data.data.published;
                        this.activeLocalization.published = response.data.data.published;
                        this.activeLocalization.status = response.data.data.status;
                        this.$nextTick(() => this.$emit('saved', response));
                    }

                    this.quickSave = false;
                    this.isAutosave = false;
                }).catch(e => console.error(e));
        },

        confirmPublish() {
            if (this.canPublish) {
                this.confirmingPublish = true;
            }
        },

        handleAxiosError(e) {
            this.saving = false;
            if (e.response && e.response.status === 422) {
                const { message, errors } = e.response.data;
                this.error = message;
                this.errors = errors;
                this.$toast.error(message);
                this.$reveal.invalid();
            } else if (e.response) {
                this.$toast.error(e.response.data.message);
            } else {
                this.$toast.error(e || 'Something went wrong');
            }
        },

        localizationSelected(localization) {
            if (!this.canSave) {
                if (localization.exists) this.editLocalization(localization);
                return;
            }

            if (localization.active) return;

            if (this.isDirty) {
                if (! confirm(__('Are you sure? Unsaved changes will be lost.'))) {
                    return;
                }
            }

            this.$dirty.remove(this.publishContainer);

            this.localizing = localization;

            if (localization.exists) {
                this.editLocalization(localization);
            } else if (this.localizations.length > 2 && this.originBehavior === 'select') {
                this.selectingOrigin = true;
            } else {
                this.createLocalization(localization);
            }

            if (this.isBase) {
                window.history.replaceState({}, '', localization.url);
            }
        },

        editLocalization(localization) {
            return this.$axios.get(localization.url).then(response => {
                clearTimeout(this.trackDirtyStateTimeout);
                this.trackDirtyState = false;

                const data = response.data;
                this.values = data.values;
                this.originValues = data.originValues;
                this.originMeta = data.originMeta;
                this.meta = data.meta;
                this.localizations = data.localizations;
                this.localizedFields = data.localizedFields;
                this.hasOrigin = data.hasOrigin;
                this.publishUrl = data.actions[this.action];
                this.collection = data.collection;
                this.title = data.editing ? data.values.title : this.title;
                this.actions = data.actions;
                this.fieldset = data.blueprint;
                this.isRoot = data.isRoot;
                this.permalink = data.permalink;
                this.site = localization.handle;
                this.localizing = false;
                this.initialPublished = data.values.published;
                this.readOnly = data.readOnly;

                this.trackDirtyStateTimeout = setTimeout(() => this.trackDirtyState = true, 750); // after any fieldtypes do a debounced update
            })
        },

        createLocalization(localization) {
            this.selectingOrigin = false;

            if (this.isCreating) {
                this.$nextTick(() => window.location = localization.url);
                return;
            }

            const originLocalization = this.localizations.find(e => e.handle === this.selectedOrigin);
            const url = originLocalization.url + '/localize';
            this.$axios.post(url, { site: localization.handle }).then(response => {
                this.editLocalization(response.data).then(() => {
                    this.$events.$emit('localization.created', { store: this.publishContainer });

                    if (this.originValues.published) {
                        this.setFieldValue('published', true);
                    }
                });
            });
        },

        cancelLocalization() {
            this.selectingOrigin = false;
            this.localizing = false;
        },

        localizationStatusText(localization) {
            if (! localization.exists) return 'This entry does not exist for this site.';

            return localization.published
                ? 'This entry exists in this site, and is published.'
                : 'This entry exists in this site, but is not published.';
        },

        openLivePreview() {
            this.tabsVisible = false;
            this.$wait(200)
                .then(() => {
                    this.isPreviewing = true;
                    return this.$wait(300);
                })
                .then(() => this.tabsVisible = true);
        },

        closeLivePreview() {
            this.isPreviewing = false;
            this.tabsVisible = true;
        },

        publishActionCompleted({ published, isWorkingCopy, response }) {
            this.saving = false;
            if (published !== undefined) {
                this.$refs.container.setFieldValue('published', published);
                this.initialPublished = published;
            }
            this.$refs.container.saved();
            this.isWorkingCopy = isWorkingCopy;
            this.confirmingPublish = false;

            let nextAction = this.quickSave || this.isAutosave ? 'continue_editing' : this.afterSaveOption;

            // If the user has opted to create another entry, redirect them to create page.
            if (!this.isInline && nextAction === 'create_another') {
                window.location = this.createAnotherUrl;
            }

            // If the user has opted to go to listing (default/null option), redirect them there.
            else if (!this.isInline && nextAction === null) {
                window.location = this.listingUrl;
            }

            // Otherwise, leave them on the edit form and emit an event. We need to wait until after
            // the hooks are resolved because if this form is being shown in a stack, we only
            // want to close it once everything's done.
            else {
                this.title = response.data.data.title;
                clearTimeout(this.trackDirtyStateTimeout);
                this.trackDirtyState = false;
                this.values = this.resetValuesFromResponse(response.data.data.values);
                this.trackDirtyStateTimeout = setTimeout(() => (this.trackDirtyState = true), 750);
                this.activeLocalization.title = response.data.data.title;
                this.activeLocalization.published = response.data.data.published;
                this.activeLocalization.status = response.data.data.status;
                this.permalink = response.data.data.permalink
                this.$nextTick(() => this.$emit('saved', response));
            }
        },

        publishActionFailed() {
            this.confirmPublish = false;
            this.saving = false;
        },

        setFieldValue(handle, value) {
            if (this.hasOrigin) this.desyncField(handle);

            this.$refs.container.setFieldValue(handle, value);
        },

        syncField(handle) {
            if (! confirm('Are you sure? This field\'s value will be replaced by the value in the original entry.'))
                return;

            this.localizedFields = this.localizedFields.filter(field => field !== handle);
            this.$refs.container.setFieldValue(handle, this.originValues[handle]);

            // Update the meta for this field. For instance, a relationship field would have its data preloaded into it.
            // If you sync the field, the preloaded data would be outdated and an ID would show instead of the titles.
            this.meta[handle] = this.originMeta[handle];
        },

        desyncField(handle) {
            if (!this.localizedFields.includes(handle))
                this.localizedFields.push(handle);

            this.$refs.container.dirty();
        },

        setAutosaveInterval() {
            const interval = setInterval(() => {
                if (!this.isDirty) return;

                this.isAutosave = true;
                this.save();
            }, this.autosaveInterval);

            this.$store.commit(`publish/${this.publishContainer}/setAutosaveInterval`, interval);
        },

        afterActionSuccessfullyCompleted(response) {
            if (response.data) {
                this.title = response.data.title;
                if (!this.revisionsEnabled) this.permalink = response.data.permalink;
                clearTimeout(this.trackDirtyStateTimeout);
                this.trackDirtyState = false;
                this.values = this.resetValuesFromResponse(response.data.values);
                this.trackDirtyStateTimeout = setTimeout(() => (this.trackDirtyState = true), 750);
                this.initialPublished = response.data.published;
                this.activeLocalization.published = response.data.published;
                this.activeLocalization.status = response.data.status;
                this.itemActions = response.data.itemActions;
            }
        },

    },

    mounted() {
        this.saveKeyBinding = this.$keys.bindGlobal(['mod+return'], e => {
            e.preventDefault();
            if (this.confirmingPublish) return;
            this.save();
        });

        this.quickSaveKeyBinding = this.$keys.bindGlobal(['mod+s'], e => {
            e.preventDefault();
            if (this.confirmingPublish) return;
            this.quickSave = true;
            this.save();
        });

        this.$store.commit(`publish/${this.publishContainer}/setPreloadedAssets`, this.preloadedAssets);

        if (typeof this.autosaveInterval === 'number') {
            this.setAutosaveInterval();
        }
    },

    created() {
        window.history.replaceState({}, document.title, document.location.href.replace('created=true', ''));

        this.selectedOrigin = this.originBehavior === 'active'
            ? this.localizations.find(l => l.active)?.handle
            : this.localizations.find(l => l.root)?.handle;
    },

    unmounted() {
        clearTimeout(this.trackDirtyStateTimeout);
    },

    beforeDestroy() {
        this.$store.commit(`publish/${this.publishContainer}/clearAutosaveInterval`);
    },

    destroyed() {
        this.saveKeyBinding.destroy();
        this.quickSaveKeyBinding.destroy();
    }

}
</script>
