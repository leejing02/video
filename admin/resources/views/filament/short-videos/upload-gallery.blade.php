<x-filament-panels::page>
    <x-filament::section>
        <form x-data="{
            files: [], category: '', batch: '', busy: false, selectionError: '', submitError: '',
            add(event) {
                if (this.batch) return;
                const selected = Array.from(event.target.files);
                event.target.value = '';
                if (this.files.length + selected.length > 100) {
                    this.selectionError = '每批最多上传 100 个文件';
                    return;
                }
                this.selectionError = '';
                this.files.push(...selected.map(file => ({ file, done: false, progress: 0, status: '待上传' })));
            },
            clear() {
                this.files = [];
                this.batch = '';
                this.selectionError = '';
                this.$refs.files.value = '';
            },
            async upload() {
                if (this.busy) return;
                this.submitError = '';
                if (!this.category) { this.submitError = '请选择所属分类'; return; }
                if (!this.files.length) { this.submitError = '请选择要上传的文件'; return; }
                this.batch ||= crypto.randomUUID();
                this.busy = true;
                try {
                    for (const [order, entry] of this.files.entries()) {
                        if (entry.done) continue;
                        entry.progress = 0;
                        entry.status = '正在上传到后台';
                        const data = new FormData();
                        data.append('batch', this.batch);
                        data.append('category', this.category);
                        data.append('order', order);
                        data.append('file', entry.file);
                        try {
                            const response = await new Promise((resolve, reject) => {
                                const xhr = new XMLHttpRequest();
                                xhr.open('POST', this.$el.action);
                                xhr.setRequestHeader('Accept', 'application/json');
                                xhr.setRequestHeader('X-CSRF-TOKEN', this.$refs.csrf.value);
                                xhr.responseType = 'json';
                                xhr.upload.onprogress = event => {
                                    if (event.lengthComputable) {
                                        entry.progress = Math.min(100, Math.floor(event.loaded / event.total * 100));
                                    }
                                };
                                xhr.upload.onload = () => {
                                    entry.progress = 100;
                                    entry.status = '后台正在提交给 EFV';
                                };
                                xhr.onload = () => resolve(xhr);
                                xhr.onerror = reject;
                                xhr.onabort = reject;
                                xhr.send(data);
                            });
                            entry.done = response.status >= 200 && response.status < 300 && !!response.response;
                            entry.status = response.response?.message || '上传失败（HTTP ' + response.status + '）';
                            if (response.status === 503) break;
                        } catch {
                            entry.status = '上传未确认，可重试';
                        }
                    }
                } finally {
                    this.busy = false;
                }
            }
        }" action="{{ route('admin.efv-gallery.upload') }}" novalidate x-on:submit.prevent="upload" class="space-y-5">
            <input type="hidden" x-ref="csrf" value="{{ csrf_token() }}">
            <p>每批文件进入同一个 EFV 合集。视频提交后由 EFV 转码。</p>
            <div>
                <label for="gallery-category" class="block font-medium">所属分类</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="gallery-category" x-model="category" x-bind:disabled="!!batch" required>
                        <option value="">请选择短视频分类</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }} · {{ $category->efv_section === 'chigua' ? '吃瓜' : '图集' }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div>
                <label for="gallery-files" class="block font-medium">图片和视频</label>
                <input id="gallery-files" type="file" x-ref="files" x-on:change="add" x-bind:disabled="!!batch"
                    accept="image/jpeg,image/png,image/webp,.mp4,.mkv,.avi,.dat,.mpg,.rmvb,.rm,.flv,.mpeg,.mov,.wmv,.vob,.asf,.ts" multiple>
                <p class="text-sm">可分批选择，最多 100 个文件。图片限 15 MB，视频限 45 MB。</p>
                <p role="alert" x-text="selectionError"></p>
            </div>
            <ol aria-live="polite" class="space-y-1">
                <template x-for="(entry, index) in files" x-bind:key="index">
                    <li>
                        <span x-text="entry.file.name"></span> · <span x-text="entry.status"></span>
                        <div>
                            <progress max="100" x-bind:value="entry.progress" x-bind:aria-label="'上传到后台：' + entry.file.name"></progress>
                            <span x-text="entry.progress + '%'"></span>
                        </div>
                    </li>
                </template>
            </ol>
            <p role="alert" x-show="submitError" x-text="submitError"></p>
            <x-filament::button type="submit" x-bind:disabled="busy || (files.length > 0 && files.every(entry => entry.done))">
                <span x-text="busy ? '正在上传' : batch ? '重试未成功文件' : '开始批量上传'"></span>
            </x-filament::button>
            <x-filament::button type="button" color="gray" x-bind:disabled="busy" x-on:click="clear">清空待上传</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
