// source-index.js - 资源管理页面 Vue 逻辑
        // 文本截断过滤器
        Vue.filter('truncate', function(value, length) {
            if (!value) return '';
            if (value.length <= length) return value;
            return value.substring(0, length) + '...';
        });

        var app = new Vue({
            el: '#app',
            mounted() {
                // 把 header 操作按钮移到 el-header 内部
                var headerActions = document.querySelector('.header-actions');
                var elHeader = document.querySelector('.el-header');
                if (headerActions && elHeader) {
                    elHeader.appendChild(headerActions);
                }
                // 获取资源统计
                this.getSourceStats();
            },
            data() {
                return {
                    sourceStats: { total: 0, today: 0, month: 0, category: 0 },
                    dialogCategory: false,
                    catDataList: [],
                    catLoading: false,
                    catDialogAdd: false,
                    catDialogEdit: false,
                    catFormLabelWidth: '80px',
                    catFormAdd: { image: '', sort: 0 },
                    catFormEdit: {},
                    catRules: {
                        name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
                    },
                    search: {
                        keyword: "",
                        filter: "title",
                        source_category_id: '',
                        is_type: '',
                        account_name: '',
                        is_time: 0,
                        is_top: ''
                    },
                    formLabelWidth: '120px',
                    rankNames: rankNames,
                    dialogFormAdd: false,
                    dialogFormEdit: false,
                    dialogCoverSearch: false,
                    coverSearchKeyword: '',
                    coverSearchType: 'movie',
                    coverResults: [],
                    coverLoading: false,
                    coverTarget: 'add',
                    coverReplaceTitle: true,
                    loading: true,
                    selectList: [],
                    dataList: [],
                    form: {
                        page: 1,
                        per_page: 10,
                        order: 'update_time desc',
                        sort_field: '',
                        sort_order: ''
                    },
                    formAdd: {
                    },
                    formEdit: {
                    },
                    rules: {
                        title: [{ required: true, message: '请输入资源名称', trigger: 'blur' }],
                        url: [{ required: true, message: '请输入资源地址', trigger: 'blur' }],
                    },
                    dialogImport: false,
                    Importform: {

                    },

                    category: [],
                    accountList: [],

                    dialogBatch: false,
                    Batchform: {},
                    dialogBatchCategory: false,
                    batchCategoryForm: {},
                    filterVisible: false,
                    viewMode: 'table',
                    // 刮削进度
                    dialogScrape: false,
                    scrapeList: [],
                    scrapeStat: { success: 0, fail: 0, skip: 0, current: -1 },
                    scrapeStatusText: '',
                    scrapeCountdown: 0,
                    scrapeAborted: false,
                    scrapeStarted: false,
                    scrapeTimer: null,
                    scrapePendingRecords: null,
                    scrapeConfig: {
                        source: 'baidu',
                        delayMin: 5,
                        delayMax: 8,
                        retryDelay: 8,
                        failPause: 20,
                        batchSize: 10,
                        batchPause: 10,
                    },
                    // 资源订阅
                    dialogSubscribe: false,
                    subscribeView: 'list',
                    subscribeLoading: false,
                    subscribeSaving: false,
                    subscribeList: [],
                    subscribeErrors: [],
                    subscribeForm: {},
                    // panbot 同步
                    dialogPanBot: false,
                    panBotLoading: false,
                    panBotSaving: false,
                    panBotTesting: false,
                    panBotResyncing: false,
                    panBotConfig: {},
                    panBotForm: {
                        base_url: '',
                        api_key: '',
                        interval_minutes: 360
                    },
                    panBotRecent: {
                        list: [],
                        total: 0,
                        since: 0,
                        limit: 50
                    },
                    // panbot 同步分类勾选
                    panBotCategories: [],
                    panBotCatSelected: [],
                    panBotCatsLoading: false,
                    panBotCatsSaving: false,
                    panBotCatTimer: null,
                }
            },
            created() {
                this.getcategory();
                this.getAdminList();
            },
            computed: {
                coverSearchTip() {
                    var tips = {
                        movie: '搜索豆瓣影视库，选择后自动填充封面、名称和简介',
                        game: '搜索豆瓣游戏库，选择后自动填充封面、名称和简介',
                        book: '搜索豆瓣图书库，选择后自动填充封面、名称和简介',
                        gamersky: '搜索游民星空游戏库，选择后自动填充封面',
                        bing: '搜索Bing图片，选择后仅设置封面，不修改名称',
                        baidu: '搜索百度图片，选择后仅设置封面，不修改名称',
                    };
                    return tips[this.coverSearchType] || '';
                },
                scrapeSourceLabel() {
                    var map = { baidu: '百度图片', bing: 'Bing图片', movie: '豆瓣影视', game: '豆瓣游戏', book: '豆瓣图书', gamersky: '游民星空' };
                    return map[this.scrapeConfig.source] || this.scrapeConfig.source;
                }
            },
            methods: {
                // 网盘类型显示（资源行的标签）
                panTypeLabel(t) {
                    var map = ['夸克', '阿里', '百度', 'UC', '迅雷', '123', '115', '天翼', '移动', '磁力', '小说'];
                    return map[t] || ('类型' + (t || 0));
                },
                panTypeTagType(t) {
                    // 夸克=primary，其它 warning/info 轮换
                    if (t == 0) return '';
                    if (t == 1 || t == 2) return 'success';
                    if (t == 4 || t == 6) return 'warning';
                    return 'info';
                },
                getList_search(val) {
                    if (val == 0) {
                        this.search = {
                            keyword: "",
                            filter: "title",
                            source_category_id: '',
                            is_type: '',
                            account_name: '',
                            is_time: 0,
                            is_top: ''
                        }
                        // 重置排序
                        this.form.sort_field = '';
                        this.form.sort_order = '';
                        this.form.order = 'update_time desc';
                    }
                    this.form.page = 1;
                    this.getList();
                },
                handleSizeChange(per_page) {
                    this.form.per_page = per_page;
                    this.getList();
                },
                // 处理表格排序
                handleSortChange({ column, prop, order }) {
                    if (prop === 'page_views') {
                        this.form.sort_field = 'page_views';
                        if (order === 'ascending') {
                            this.form.sort_order = 'asc';
                            this.form.order = 'page_views asc';
                        } else if (order === 'descending') {
                            this.form.sort_order = 'desc';
                            this.form.order = 'page_views desc';
                        } else {
                            // 取消排序，恢复默认
                            this.form.sort_field = '';
                            this.form.sort_order = '';
                            this.form.order = 'update_time desc';
                        }
                        this.form.page = 1;
                        this.getList();
                    }
                },
                postEdit() {
                    var that = this;
                    that.$refs["formEdit"].validate((valid) => {
                        if (valid) {
                            axios.post('/admin/source/update', Object.assign({}, PostBase, that.formEdit))
                                .then(function (response) {
                                    that.getList();
                                    if (response.data.code == CODE_SUCCESS) {
                                        that.$message({
                                            message: response.data.message,
                                            type: 'success'
                                        });
                                        that.dialogFormEdit = false;
                                    } else {
                                        that.$message.error(response.data.message);
                                    }
                                })
                                .catch(function (error) {
                                    that.$message.error('服务器内部错误');
                                    console.log(error);
                                });
                        }
                    });
                },
                postAdd() {
                    var that = this;
                    that.$refs['formAdd'].validate((valid) => {
                        if (valid) {
                            if (!that.formAdd.title) {
                                that.$message.error('请输入资源名称');
                                return;
                            }
                            
                            axios.post('/admin/source/add', Object.assign({}, PostBase, that.formAdd))
                                .then(function (response) {
                                    that.getList();
                                    if (response.data.code == CODE_SUCCESS) {
                                        that.$message({
                                            message: response.data.message,
                                            type: 'success'
                                        });
                                        that.dialogFormAdd = false;
                                    } else {
                                        that.$message.error(response.data.message);
                                    }
                                })
                                .catch(function (error) {
                                    that.$message.error('服务器内部错误');
                                });
                        }
                    });
                },
                getSourceStats() {
                    var that = this;
                    axios.post('/admin/source/stats', Object.assign({}, PostBase))
                        .then(function(res) {
                            if (res.data.code == 200) {
                                that.sourceStats = res.data.data;
                            }
                        });
                },
                openCategory() {
                    this.dialogCategory = true;
                    this.catGetList();
                },
                catGetList() {
                    var that = this;
                    that.catLoading = true;
                    axios.post('/admin/source_category/getList', Object.assign({}, PostBase))
                        .then(function(response) {
                            that.catLoading = false;
                            if (response.data.code == CODE_SUCCESS) {
                                that.catDataList = response.data.data;
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function(error) {
                            that.catLoading = false;
                            that.$message.error('服务器内部错误');
                        });
                },
                catClickAdd() {
                    this.catFormAdd = { sort: 0, image: '' };
                    this.catDialogAdd = true;
                },
                catPostAdd() {
                    var that = this;
                    that.$refs['catFormAdd'].validate((valid) => {
                        if (valid) {
                            axios.post('/admin/source_category/add', Object.assign({}, PostBase, that.catFormAdd))
                                .then(function(response) {
                                    that.catGetList();
                                    if (response.data.code == CODE_SUCCESS) {
                                        that.$message({ message: response.data.message, type: 'success' });
                                        that.catDialogAdd = false;
                                    } else {
                                        that.$message.error(response.data.message);
                                    }
                                })
                                .catch(function(error) {
                                    that.$message.error('服务器内部错误');
                                });
                        }
                    });
                },
                catClickEdit(row) {
                    var that = this;
                    axios.post('/admin/source_category/detail', Object.assign({}, PostBase, {
                        source_category_id: row.source_category_id
                    }))
                        .then(function(response) {
                            if (response.data.code == CODE_SUCCESS) {
                                that.catFormEdit = response.data.data;
                                that.catFormEdit.image = that.catFormEdit.image || '';
                                that.catDialogEdit = true;
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function(error) {
                            that.$message.error('服务器内部错误');
                        });
                },
                catPostEdit() {
                    var that = this;
                    that.$refs['catFormEdit'].validate((valid) => {
                        if (valid) {
                            axios.post('/admin/source_category/update', Object.assign({}, PostBase, that.catFormEdit))
                                .then(function(response) {
                                    that.catGetList();
                                    if (response.data.code == CODE_SUCCESS) {
                                        that.$message({ message: response.data.message, type: 'success' });
                                        that.catDialogEdit = false;
                                    } else {
                                        that.$message.error(response.data.message);
                                    }
                                })
                                .catch(function(error) {
                                    that.$message.error('服务器内部错误');
                                });
                        }
                    });
                },
                catClickDelete(row) {
                    var that = this;
                    this.$confirm('即将删除这个分类, 是否确认?', '删除提醒', {
                        confirmButtonText: '删除',
                        cancelButtonText: '取消',
                        type: 'warning'
                    }).then(() => {
                        axios.post('/admin/source_category/delete', Object.assign({}, PostBase, {
                            source_category_id: row.source_category_id
                        }))
                            .then(function(response) {
                                that.catGetList();
                                if (response.data.code == CODE_SUCCESS) {
                                    that.$message({ message: response.data.message, type: 'success' });
                                } else {
                                    that.$message.error(response.data.message);
                                }
                            })
                            .catch(function(error) {
                                that.$message.error('服务器内部错误');
                            });
                    }).catch(() => {});
                },
                catClickStatus(source_category_id, type, status) {
                    var that = this;
                    axios.post('/admin/source_category/setStatus', Object.assign({}, PostBase, {
                        source_category_id: source_category_id,
                        type: type,
                        status: status
                    }))
                        .then(function(response) {
                            that.catGetList();
                            if (response.data.code == CODE_SUCCESS) {
                                that.$message({ message: response.data.message, type: 'success' });
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function(error) {
                            that.$message.error('服务器内部错误');
                        });
                },
                clickAdd() {
                    var that = this;
                    that.formAdd = { status: 1, share_image: '', account_name: adminName, is_top: 0, is_time: 0, vod_pic: '' };
                    that.dialogFormAdd = true;
                },
                fetchCoverAdd() {
                    var title = this.formAdd.title;
                    this.coverSearchKeyword = title || '';
                    this.coverSearchType = 'movie';
                    this.coverTarget = 'add';
                    this.coverResults = [];
                    this.dialogCoverSearch = true;
                },
                fetchCoverEdit() {
                    var title = this.formEdit.title;
                    this.coverSearchKeyword = title || '';
                    this.coverSearchType = 'movie';
                    this.coverTarget = 'edit';
                    this.coverResults = [];
                    this.dialogCoverSearch = true;
                },
                clearCoverEdit() {
                    var that = this;
                    if (!that.formEdit.vod_pic) {
                        that.$message.info('当前没有封面图');
                        return;
                    }
                    that.$confirm('确定清除该资源的封面图?', '清除封面', {
                        confirmButtonText: '清除',
                        cancelButtonText: '取消',
                        type: 'warning'
                    }).then(() => {
                        axios.post('/admin/source/update', Object.assign({}, PostBase, that.formEdit, {
                            vod_pic: ''
                        })).then(function (response) {
                            if (response.data.code == CODE_SUCCESS) {
                                that.formEdit.vod_pic = '';
                                that.$message.success('封面已清除');
                            } else {
                                that.$message.error(response.data.message || '清除失败');
                            }
                        }).catch(function (error) {
                            that.$message.error('服务器内部错误');
                        });
                    }).catch(() => {
                    });
                },
                switchCoverTab(type) {
                    this.coverSearchType = type;
                    this.coverResults = [];
                    this.coverReplaceTitle = (type === 'movie' || type === 'game' || type === 'book');
                },
                doSearchCover() {
                    var keyword = this.coverSearchKeyword.trim();
                    if (!keyword) {
                        this.$message.warning('请输入资源名称');
                        return;
                    }
                    var that = this;
                    that.coverLoading = true;
                    that.coverResults = [];
                    axios.post('/admin/source/searchCover', Object.assign({}, PostBase, { keyword: keyword, type: that.coverSearchType }))
                        .then(function (response) {
                            that.coverLoading = false;
                            if (response.data.code == CODE_SUCCESS) {
                                that.coverResults = response.data.data || [];
                                if (that.coverResults.length === 0) {
                                    that.$message.info('未找到封面图');
                                }
                            } else {
                                that.$message.error(response.data.message || '搜索失败');
                            }
                        })
                        .catch(function (error) {
                            that.coverLoading = false;
                            that.$message.error('搜索失败，请重试');
                        });
                },
                selectCover(item) {
                    if (!item.cover) {
                        this.$message.warning('该结果无封面图');
                        return;
                    }
                    var that = this;
                    that.$message({ message: '正在下载封面图...', type: 'info', duration: 0 });
                    axios.post('/admin/source/downloadCover', Object.assign({}, PostBase, { url: item.cover }))
                        .then(function (response) {
                            that.$message.closeAll();
                            if (response.data.code == CODE_SUCCESS) {
                                var path = response.data.data.attach_path;
                                // 拼接资源介绍：导演信息 + 简介
                                var content = '';
                                if (item.abstract) content += item.abstract;
                                if (item.abstract_2) content += (content ? '\n\n' : '') + item.abstract_2;
                                var fullTitle = item.title || '';
                                if (item.sub_title) fullTitle += ' ' + item.sub_title;
                                if (item.year) fullTitle += ' (' + item.year + ')';
                                if (that.coverTarget === 'add') {
                                    that.formAdd.vod_pic = path;
                                    if (fullTitle && that.coverReplaceTitle) that.formAdd.title = fullTitle;
                                    if (content) that.formAdd.vod_content = content;
                                } else {
                                    that.formEdit.vod_pic = path;
                                    if (fullTitle && that.coverReplaceTitle) that.formEdit.title = fullTitle;
                                    if (content) that.formEdit.vod_content = content;
                                }
                                that.dialogCoverSearch = false;
                                that.$message.success('已设置封面、名称和介绍');
                            } else {
                                that.$message.error(response.data.message || '下载失败');
                            }
                        })
                        .catch(function (error) {
                            that.$message.closeAll();
                            that.$message.error('下载失败，请重试');
                        });
                },
                coverImgError(index) {
                    if (this.coverResults[index]) {
                        this.coverResults[index].cover = '';
                        this.coverResults[index].cover_proxy = '';
                    }
                },
                // 资源卡片封面加载失败兜底（如 PanBot 签名直链过期）：隐藏破图并显示占位图标
                cardCoverError(e, placeholderClass) {
                    var img = e.target;
                    if (!img || img.dataset.fallbackDone) return;
                    img.dataset.fallbackDone = '1';
                    img.style.display = 'none';
                    if (img.parentElement && !img.parentElement.querySelector('.cover-fallback-placeholder')) {
                        var i = document.createElement('i');
                        i.className = 'el-icon-picture-outline ' + (placeholderClass || 'source-card-cover-placeholder') + ' cover-fallback-placeholder';
                        img.parentElement.appendChild(i);
                    }
                },
                handleCoverSuccessAdd(res, file) {
                    if (res.code == CODE_SUCCESS) {
                        this.formAdd.vod_pic = res.data.attach_path;
                        this.$message.success('封面上传成功');
                    } else {
                        this.$message.error(res.message || '上传失败');
                    }
                },
                handleCoverSuccessEdit(res, file) {
                    if (res.code == CODE_SUCCESS) {
                        this.formEdit.vod_pic = res.data.attach_path;
                        this.$message.success('封面上传成功');
                    } else {
                        this.$message.error(res.message || '上传失败');
                    }
                },
                beforeCoverUpload(file) {
                    const isJPG = file.type === 'image/jpeg';
                    const isPNG = file.type === 'image/png';
                    const isGIF = file.type === 'image/gif';
                    const isWEBP = file.type === 'image/webp';
                    const isLt2M = file.size / 1024 / 1024 < 2;

                    if (!isJPG && !isPNG && !isGIF && !isWEBP) {
                        this.$message.error('只支持 JPG/PNG/GIF/WEBP 格式的图片!');
                        return false;
                    }
                    if (!isLt2M) {
                        this.$message.error('图片大小不能超过 2MB!');
                        return false;
                    }
                    return true;
                },
                clickDelete(row) {
                    var that = this;
                    this.$confirm('删除后，资源将无法查看，是否继续删除?', '删除提醒', {
                        confirmButtonText: '删除',
                        cancelButtonText: '取消',
                        type: 'warning'
                    }).then(() => {
                        axios.post('/admin/source/delete', Object.assign({}, PostBase, {
                            source_id: row.source_id
                        }))
                            .then(function (response) {
                                that.getList();
                                if (response.data.code == CODE_SUCCESS) {
                                    that.$message({
                                        message: response.data.message,
                                        type: 'success'
                                    });
                                } else {
                                    that.$message.error(response.data.message);
                                }
                            })
                            .catch(function (error) {
                                that.$message.error('服务器内部错误');
                            });
                    }).catch(() => {
                    });
                },
                clickEdit(row) {
                    var that = this;
                    that.formEdit = row;
                    axios.post('/admin/source/detail', Object.assign({}, PostBase, {
                        source_id: row.source_id
                    }))
                        .then(function (response) {
                            if (response.data.code == CODE_SUCCESS) {
                                response.data.data.source_category_id = response.data.data.source_category_id || undefined
                                that.formEdit = response.data.data;
                                that.dialogFormEdit = true;
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.$message.error('服务器内部错误');
                        });
                },
                changeCurrentPage(page) {
                    this.form.page = page;
                    this.getList();
                },
                getcategory() {
                    var that = this;
                    axios.post('/admin/source_category/getList', Object.assign({}, PostBase))
                        .then(function (response) {
                            that.loading = false;
                            if (response.data.code == CODE_SUCCESS) {
                                that.category = response.data.data;
                                that.getList();
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.loading = false;
                            that.$message.error('服务器内部错误');
                            console.log(error);
                        });
                },
                getList() {
                    var that = this;
                    that.loading = true;
                    axios.post('/admin/source/getList', Object.assign({}, PostBase, that.form, that.search))
                        .then(function (response) {
                            that.loading = false;
                            if (response.data.code == CODE_SUCCESS) {
                                that.dataList = response.data.data;
                                that.setcategory();
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.loading = false;
                            that.$message.error('服务器内部错误');
                        });
                },
                setcategory() {
                    for (let item of this.dataList.data) {
                        for (let items of this.category) {
                            if (item.source_category_id == items.source_category_id) {
                                item.source_category_id_name = items.name
                            }
                        }
                    }
                },
                getAdminList() {
                    var that = this;
                    axios.post('/admin/admin/getList', Object.assign({}, PostBase, {
                        page: 1,
                        per_page: 1000
                    }))
                    .then(function (response) {
                        if (response.data.code == CODE_SUCCESS) {
                            var admins = new Set();
                            for (let item of response.data.data.data) {
                                if (item.admin_account) {
                                    admins.add(item.admin_account);
                                }
                            }
                            that.accountList = Array.from(admins).sort();
                        }
                    })
                    .catch(function (error) {
                        console.log(error);
                    });
                },

                //导入数据
                ImportShow() {
                    this.Importform = {
                        mode: 0,
                        is_time: 0,
                        textInput: ''
                    }
                    this.dialogImport = true
                },
                handleExceed(files, fileList) {
                    this.$message.warning(`只能选择一个文件`);
                },
                handleAvatarSuccess(res, file) {
                    if (res.code == 200) {
                        this.getList();
                        this.$message({
                            message: res.message,
                            type: 'success'
                        });
                        this.dialogImport = false;
                    } else {
                        this.$message.error(res.message);
                    }
                    this.$refs.ImportUpload.clearFiles();
                },
                ImportPost() {
                    this.Importform = Object.assign(this.Importform, PostBase)
                    this.$nextTick(() => {
                        this.$refs.ImportUpload.submit();
                    })
                },
                ImportTextInput() {
                    var that = this;
                    if (!that.Importform.textInput || !that.Importform.textInput.trim()) {
                        that.$message.error('请输入资源内容');
                        return;
                    }
                    axios.post('/admin/source/transfer', Object.assign({}, PostBase, {
                        type: 1,
                        urls: that.Importform.textInput,
                        source_category_id: that.Importform.source_category_id || 0,
                        is_time: that.Importform.is_time || 0
                    })).then(function(res) {
                        var stat = res.data.data || {};
                        that.$message({
                            message: '导入完成：成功 ' + (stat.success || 0) + ' 条，跳过 ' + (stat.skip || 0) + ' 条，失败 ' + (stat.fail || 0) + ' 条',
                            type: 'success'
                        });
                        that.getList();
                        that.dialogImport = false;
                    }).catch(function(error) {
                        that.$message.error('服务器内部错误');
                    });
                },

                //批量导入数据
                ImportBatch() {
                    this.Batchform = {}
                    this.dialogBatch = true
                },
                BatchPost() {
                    var that = this;
                    if (!that.Batchform.type) return that.$message.error('请选择导入方式');
                    if (!that.Batchform.urls) return that.$message.error('请输入资源地址');
                    axios.post('/admin/source/transfer', Object.assign({}, PostBase, that.Batchform))
                        .then(function (res) {
                        })
                        .catch(function (error) {
                            that.$message.error('服务器内部错误');
                        });
                    that.$message({
                        message: "已提交任务，稍后查看结果",
                        type: 'success'
                    });
                },


                //数据导出
                getExport() {
                    var that = this;
                    var filters = Object.assign({}, PostBase, that.search);
                    var url = '/admin/source/excel?';
                    for (let key in filters) {
                        url += key + "=" + filters[key] + "&";
                    }
                    window.open(url);
                    that.$message.success('数据导出成功');
                },


                postMultDelete() {
                    var that = this;
                    if (that.selectList.length == 0) {
                        that.$message.error('未选择任何资源！');
                        return;
                    }
                    this.$confirm('即将删除选中的资源, 是否确认?', '批量删除', {
                        confirmButtonText: '删除',
                        cancelButtonText: '取消',
                        type: 'warning'
                    }).then(() => {
                        axios.post('/admin/source/delete', Object.assign({}, PostBase, {
                            source_id: that.selectList.join(",")
                        }))
                            .then(function (response) {
                                that.getList();
                                if (response.data.code == CODE_SUCCESS) {
                                    that.$message({
                                        message: response.data.message,
                                        type: 'success'
                                    });
                                } else {
                                    that.$message.error(response.data.message);
                                }
                            })
                            .catch(function (error) {
                                that.$message.error('服务器内部错误');
                                console.log(error);
                            });
                    }).catch(() => {
                    });
                },
                changeSelection(list) {
                    var that = this;
                    that.selectList = [];
                    for (var index in list) {
                        that.selectList.push(list[index].source_id);
                    }
                },
                toggleCardSelection(item) {
                    var idx = this.selectList.indexOf(item.source_id);
                    if (idx > -1) {
                        this.selectList.splice(idx, 1);
                    } else {
                        this.selectList.push(item.source_id);
                    }
                },

                // 智能刮削封面
                scrapeCovers() {
                    var that = this;
                    if (that.selectList.length == 0) {
                        that.$message.error('请先选择需要刮削的资源！');
                        return;
                    }
                    var selectedRecords = (that.dataList.data || []).filter(function (item) {
                        return that.selectList.indexOf(item.source_id) > -1;
                    });
                    var noCoverRecords = selectedRecords.filter(function (item) {
                        return !item.vod_pic;
                    });
                    if (noCoverRecords.length == 0) {
                        that.$message.info('所选资源都有封面，无需刮削');
                        return;
                    }
                    this.$confirm('即将为 ' + noCoverRecords.length + ' 条没有封面的资源自动搜索并补充封面, 是否确认?', '智能刮削', {
                        confirmButtonText: '开始刮削',
                        cancelButtonText: '取消',
                        type: 'info'
                    }).then(() => {
                        that.doScrapeCovers(noCoverRecords);
                    }).catch(() => {
                    });
                },

                // 执行刮削封面（初始化列表，弹窗，等待用户点击开始）
                doScrapeCovers(records) {
                    var that = this;
                    var list = records.map(function (r, i) {
                        return {
                            source_id: r.source_id,
                            title: r.title,
                            status: 'pending',
                            reason: '',
                            countdown: 0,
                            _record: r,
                            _retry: false
                        };
                    });
                    that.scrapeList = list;
                    that.scrapeStat = { success: 0, fail: 0, skip: 0, current: -1 };
                    that.scrapeStatusText = '点击「开始刮削」按钮启动';
                    that.scrapeCountdown = 0;
                    that.scrapeStarted = false;
                    that.scrapeAborted = false;
                    that.scrapePendingRecords = records;
                    that.dialogScrape = true;
                },
                // 开始执行刮削
                scrapeStart() {
                    var that = this;
                    var records = this.scrapePendingRecords;
                    if (!records) return;
                    var list = this.scrapeList;
                    var cfg = this.scrapeConfig;
                    var sourceType = cfg.source;
                    this.scrapeStarted = true;
                    this.scrapeStat.current = 0;
                    this.scrapeStatusText = '正在预热' + this.scrapeSourceLabel + '...';

                    // 预热（百度图片需要先获取 cookie）
                    this.preheatSource(sourceType).then(function () {
                        that.runScrape(records, list, cfg, sourceType);
                    }).catch(function () {
                        // 预热失败也继续，不阻塞
                        that.runScrape(records, list, cfg, sourceType);
                    });
                },
                // 预热刮削源
                preheatSource(sourceType) {
                    return axios.post('/admin/source/preheatCover', Object.assign({}, PostBase, {
                        source: sourceType
                    }));
                },
                // 实际执行刮削
                runScrape(records, list, cfg, sourceType) {
                    var that = this;
                    var index = 0;
                    var consecutiveFail = 0;
                    var backoffMultiplier = 1; // 指数退避系数，成功重置为1，失败翻倍（上限4）

                    function getNextDelay() {
                        var base = (cfg.delayMin + Math.floor(Math.random() * (cfg.delayMax - cfg.delayMin + 1))) * 1000;
                        return Math.min(base * backoffMultiplier, 30000); // 上限30秒
                    }
                    function getRetryDelay() { return Math.min(cfg.retryDelay * 1000 * backoffMultiplier, 30000); }
                    function getBatchDelay() { return cfg.batchPause * 1000; }

                    // 倒计时显示
                    function startCountdown(seconds, text) {
                        that.scrapeStatusText = text;
                        that.scrapeCountdown = seconds;
                        if (that.scrapeTimer) clearInterval(that.scrapeTimer);
                        that.scrapeTimer = setInterval(function () {
                            that.scrapeCountdown--;
                            // 同步到当前 pending 行
                            if (index < list.length && list[index].status === 'pending') {
                                that.$set(list[index], 'countdown', that.scrapeCountdown);
                            }
                            if (that.scrapeCountdown <= 0) {
                                clearInterval(that.scrapeTimer);
                                that.scrapeTimer = null;
                            }
                        }, 1000);
                        // 立即设置当前行倒计时
                        if (index < list.length) {
                            that.$set(list[index], 'countdown', seconds);
                        }
                    }

                    function finish() {
                        if (that.scrapeTimer) { clearInterval(that.scrapeTimer); that.scrapeTimer = null; }
                        that.scrapeCountdown = 0;
                        that.scrapeStarted = false;
                        if (that.scrapeAborted) {
                            that.scrapeStatusText = '已终止（成功 ' + that.scrapeStat.success + ' 条，失败 ' + that.scrapeStat.fail + ' 条）';
                        } else {
                            that.scrapeStatusText = '刮削完成';
                            that.scrapeStat.current = list.length;
                            that.getList();
                            var msg = '刮削完成！成功 ' + that.scrapeStat.success + ' 条';
                            if (that.scrapeStat.skip > 0) msg += '，跳过 ' + that.scrapeStat.skip + ' 条';
                            if (that.scrapeStat.fail > 0) msg += '，失败 ' + that.scrapeStat.fail + ' 条';
                            that.$message({ message: msg, type: that.scrapeStat.fail > 0 ? 'warning' : 'success', duration: 5000 });
                        }
                    }

                    function processNext() {
                        if (that.scrapeAborted) {
                            finish();
                            return;
                        }
                        if (index >= list.length) {
                            finish();
                            return;
                        }
                        var item = list[index];
                        that.scrapeStat.current = index;

                        // 跳过已有封面
                        if (item._record.vod_pic) {
                            item.status = 'skip';
                            item.reason = '已有封面';
                            that.scrapeStat.skip++;
                            index++;
                            setTimeout(processNext, 300);
                            return;
                        }

                        var isRetry = item._retry;
                        that.scrapeStatusText = '正在刮削 (' + (index + 1) + '/' + list.length + ')' + (isRetry ? '(重试)' : '') + ': ' + item.title;
                        item.status = 'processing';
                        item.reason = isRetry ? '重试中...' : '搜索中...';
                        that.$set(list, index, item);

                        var willRetry = false;
                        axios.post('/admin/source/searchCover', Object.assign({}, PostBase, {
                            keyword: item._record.title,
                            type: sourceType
                        })).then(function (response) {
                            var results = (response.data.code == CODE_SUCCESS) ? (response.data.data || []) : [];
                            if (results.length > 0 && results[0].cover) {
                                item.reason = '下载封面中...';
                                that.$set(list, index, item);
                                return axios.post('/admin/source/downloadCover', Object.assign({}, PostBase, {
                                    url: results[0].cover
                                })).then(function (res) {
                                    if (res.data.code == CODE_SUCCESS) {
                                        return res.data.data.attach_path;
                                    }
                                    return null;
                                });
                            }
                            return null;
                        }).then(function (path) {
                            if (path) {
                                item.reason = '更新记录中...';
                                that.$set(list, index, item);
                                return axios.post('/admin/source/update', Object.assign({}, PostBase, item._record, {
                                    vod_pic: path
                                })).then(function (updateRes) {
                                    if (updateRes.data.code == CODE_SUCCESS) {
                                        item.status = 'success';
                                        item.reason = '刮削成功';
                                        that.scrapeStat.success++;
                                        consecutiveFail = 0;
                                        backoffMultiplier = 1; // 成功，重置退避
                                    } else {
                                        item.status = 'fail';
                                        item.reason = updateRes.data.message || '更新失败';
                                        that.scrapeStat.fail++;
                                        consecutiveFail++;
                                    }
                                    that.$set(list, index, item);
                                });
                            } else {
                                if (!isRetry) {
                                    item._retry = true;
                                    item.status = 'pending';
                                    item.reason = '首次失败，准备重试';
                                    that.$set(list, index, item);
                                    willRetry = true;
                                    consecutiveFail++;
                                    if (backoffMultiplier < 4) backoffMultiplier *= 2; // 退避翻倍，上限4
                                    var retrySec = Math.round(getRetryDelay() / 1000);
                                    startCountdown(retrySec, '首次失败，' + retrySec + '秒后重试(退避x' + backoffMultiplier + '): ' + item.title);
                                    setTimeout(processNext, getRetryDelay());
                                } else {
                                    item.status = 'fail';
                                    item.reason = '未找到封面图';
                                    that.scrapeStat.fail++;
                                    consecutiveFail++;
                                    that.$set(list, index, item);
                                }
                            }
                        }).catch(function (error) {
                            if (!isRetry) {
                                item._retry = true;
                                item.status = 'pending';
                                item.reason = '请求异常，准备重试';
                                that.$set(list, index, item);
                                willRetry = true;
                                consecutiveFail++;
                                if (backoffMultiplier < 4) backoffMultiplier *= 2; // 退避翻倍，上限4
                                var retrySec2 = Math.round(getRetryDelay() / 1000);
                                startCountdown(retrySec2, '请求异常，' + retrySec2 + '秒后重试(退避x' + backoffMultiplier + '): ' + item.title);
                                setTimeout(processNext, getRetryDelay());
                            } else {
                                item.status = 'fail';
                                item.reason = '请求异常: ' + (error.message || '未知错误');
                                that.scrapeStat.fail++;
                                consecutiveFail++;
                                that.$set(list, index, item);
                            }
                        }).then(function () {
                            if (willRetry) return;
                            index++;
                            var processed = that.scrapeStat.success + that.scrapeStat.fail;
                            // 连续失败 2 次，暂停
                            if (consecutiveFail >= 2) {
                                consecutiveFail = 0;
                                backoffMultiplier = 1; // 长暂停后重置退避
                                startCountdown(cfg.failPause, '风控检测，暂停 ' + cfg.failPause + ' 秒');
                                setTimeout(processNext, cfg.failPause * 1000);
                                return;
                            }
                            // 每N条休息
                            if (processed > 0 && processed % cfg.batchSize === 0) {
                                startCountdown(cfg.batchPause, '已处理 ' + processed + ' 条，休息 ' + cfg.batchPause + ' 秒');
                                setTimeout(processNext, getBatchDelay());
                                return;
                            }
                            var delay = getNextDelay();
                            startCountdown(Math.round(delay / 1000), '下一条 ' + Math.round(delay / 1000) + ' 秒后开始');
                            setTimeout(processNext, delay);
                        });
                    }

                    processNext();
                },
                // 终止刮削
                scrapeAbort() {
                    this.scrapeAborted = true;
                    if (this.scrapeTimer) { clearInterval(this.scrapeTimer); this.scrapeTimer = null; }
                    this.scrapeCountdown = 0;
                    this.scrapeStatusText = '已终止';
                    this.scrapeStarted = false;
                },
                // 刮削状态标签类型
                scrapeStatusType(status) {
                    var map = { pending: 'info', processing: 'warning', success: 'success', fail: 'danger', skip: 'info' };
                    return map[status] || 'info';
                },
                scrapeStatusText2(status) {
                    var map = { pending: '等待', processing: '处理中', success: '成功', fail: '失败', skip: '跳过' };
                    return map[status] || status;
                },
                
                // 显示批量修改分类对话框
                showBatchCategory() {
                    var that = this;
                    if (that.selectList.length == 0) {
                        that.$message.error('未选择任何资源！');
                        return;
                    }
                    that.batchCategoryForm = {};
                    that.dialogBatchCategory = true;
                },
                
                // 批量修改分类
                batchUpdateCategory() {
                    var that = this;
                    if (!that.batchCategoryForm.source_category_id) {
                        that.$message.error('请选择分类！');
                        return;
                    }
                    
                    axios.post('/admin/source/batchUpdateCategory', Object.assign({}, PostBase, {
                        source_ids: that.selectList.join(","),
                        source_category_id: that.batchCategoryForm.source_category_id
                    }))
                    .then(function (response) {
                        that.getList();
                        if (response.data.code == CODE_SUCCESS) {
                            that.$message({
                                message: response.data.message,
                                type: 'success'
                            });
                            that.dialogBatchCategory = false;
                        } else {
                            that.$message.error(response.data.message);
                        }
                    })
                    .catch(function (error) {
                        that.$message.error('服务器内部错误');
                        console.log(error);
                    });
                },

                // ==================== 资源订阅 ====================
                // 打开订阅管理弹窗
                SubscribeShow() {
                    var that = this;
                    that.dialogSubscribe = true;
                    that.subscribeView = 'list';
                    that.getSubscribeList();
                },
                // 订阅列表
                getSubscribeList() {
                    var that = this;
                    that.subscribeLoading = true;
                    axios.post('/admin/source_subscribe/getList', Object.assign({}, PostBase, { page: 1, per_page: 100 }))
                        .then(function (response) {
                            that.subscribeLoading = false;
                            if (response.data.code == CODE_SUCCESS) {
                                var rows = response.data.data.data || [];
                                that.subscribeList = rows.map(function (row) {
                                    row._pulling = false;
                                    return row;
                                });
                                // 汇总最近一次拉取有错误的订阅，列表下方提示
                                that.subscribeErrors = rows.filter(function (r) {
                                    return r.last_error;
                                });
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.subscribeLoading = false;
                            that.$message.error('服务器内部错误');
                            console.log(error);
                        });
                },
                // 新增订阅
                clickSubscribeAdd() {
                    this.subscribeForm = {
                        name: '',
                        url: '',
                        code: '',
                        client_id: '',
                        client_secret: '',
                        source_category_id: '',
                        is_time: 0,
                        pull_mode: 1,
                        interval_minutes: 60
                    };
                    this.subscribeView = 'form';
                },
                // 编辑订阅
                clickSubscribeEdit(row) {
                    this.subscribeForm = {
                        subscribe_id: row.subscribe_id,
                        name: row.name,
                        url: row.url,
                        code: row.code,
                        client_id: row.client_id || '',
                        client_secret: row.client_secret || '',
                        source_category_id: row.source_category_id || '',
                        is_time: parseInt(row.is_time) || 0,
                        pull_mode: parseInt(row.pull_mode) || 1,
                        interval_minutes: parseInt(row.interval_minutes) || 60
                    };
                    this.subscribeView = 'form';
                },
                // 保存订阅（新增/编辑）
                saveSubscribe() {
                    var that = this;
                    if (!that.subscribeForm.name || !that.subscribeForm.name.trim()) {
                        that.$message.error('请输入订阅名称');
                        return;
                    }
                    if (!that.subscribeForm.url || !that.subscribeForm.url.trim()) {
                        that.$message.error('请输入 PanBot 服务地址');
                        return;
                    }
                    if (!that.subscribeForm.code || !that.subscribeForm.code.trim()) {
                        that.$message.error('请输入订阅码');
                        return;
                    }
                    if (!that.subscribeForm.client_id || !that.subscribeForm.client_id.trim()) {
                        that.$message.error('请输入 client_id');
                        return;
                    }
                    if (!that.subscribeForm.client_secret || !that.subscribeForm.client_secret.trim()) {
                        that.$message.error('请输入 client_secret');
                        return;
                    }
                    that.subscribeSaving = true;
                    var isEdit = !!that.subscribeForm.subscribe_id;
                    axios.post('/admin/source_subscribe/' + (isEdit ? 'update' : 'add'),
                        Object.assign({}, PostBase, that.subscribeForm))
                        .then(function (response) {
                            that.subscribeSaving = false;
                            if (response.data.code == CODE_SUCCESS) {
                                that.$message.success(response.data.message);
                                that.subscribeView = 'list';
                                that.getSubscribeList();
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.subscribeSaving = false;
                            that.$message.error('服务器内部错误');
                            console.log(error);
                        });
                },
                // 启停订阅
                clickSubscribeStatus(row) {
                    var that = this;
                    var target = row.status == 1 ? 0 : 1;
                    axios.post('/admin/source_subscribe/setStatus', Object.assign({}, PostBase, {
                        subscribe_id: row.subscribe_id,
                        status: target
                    })).then(function (response) {
                        if (response.data.code == CODE_SUCCESS) {
                            that.$message.success(response.data.message);
                            that.getSubscribeList();
                        } else {
                            that.$message.error(response.data.message);
                        }
                    }).catch(function (error) {
                        that.$message.error('服务器内部错误');
                        console.log(error);
                    });
                },
                // 删除订阅（物理删除，不影响已入库资源）
                clickSubscribeDelete(row) {
                    var that = this;
                    that.$confirm('确定删除订阅「' + row.name + '」吗？已入库的资源不受影响', '提示', {
                        confirmButtonText: '确定',
                        cancelButtonText: '取消',
                        type: 'warning'
                    }).then(function () {
                        axios.post('/admin/source_subscribe/delete', Object.assign({}, PostBase, {
                            subscribe_id: row.subscribe_id
                        })).then(function (response) {
                            if (response.data.code == CODE_SUCCESS) {
                                that.$message.success(response.data.message);
                                that.getSubscribeList();
                            } else {
                                that.$message.error(response.data.message);
                            }
                        }).catch(function (error) {
                            that.$message.error('服务器内部错误');
                            console.log(error);
                        });
                    }).catch(function () {});
                },
                // 立即拉取指定订阅（忽略到期判断）
                clickPullSubscribe(row) {
                    var that = this;
                    row._pulling = true;
                    axios.post('/admin/source_subscribe/pullNow', Object.assign({}, PostBase, {
                        subscribe_id: row.subscribe_id
                    })).then(function (response) {
                        row._pulling = false;
                        if (response.data.code == CODE_SUCCESS) {
                            var stat = response.data.data || {};
                            that.$message.success(response.data.message);
                            if (stat.last_error) {
                                that.$message.warning('部分失败：' + stat.last_error);
                            }
                            that.getSubscribeList();
                        } else {
                            that.$message.error(response.data.message);
                        }
                    }).catch(function (error) {
                        row._pulling = false;
                        that.$message.error('服务器内部错误');
                        console.log(error);
                    });
                },
                // 「更多」下拉分发：edit / disable / enable / delete
                handleSubscribeCommand(cmd) {
                    if (!cmd || !cmd.row) return;
                    if (cmd.action === 'edit') this.clickSubscribeEdit(cmd.row);
                    else if (cmd.action === 'disable') this.clickSubscribeStatus(cmd.row);
                    else if (cmd.action === 'enable') this.clickSubscribeStatus(cmd.row);
                    else if (cmd.action === 'delete') this.clickSubscribeDelete(cmd.row);
                },
                // 订阅时间戳格式化
                formatSubscribeTime(ts) {
                    if (!ts) return '未拉取';
                    var d = new Date(parseInt(ts) * 1000);
                    var p = function (n) { return n < 10 ? '0' + n : n; };
                    return (d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
                },

                // ==================== panbot 同步 ====================
                // 打开 panbot 弹窗：拉取配置 + 最近实际同步到 qf_source 的资源
                PanBotShow() {
                    this.dialogPanBot = true;
                    this.getPanBotConfig();
                    this.getPanBotRecent();
                    this.getPanBotCategories();
                },
                // 获取 panbot 配置
                getPanBotConfig() {
                    var that = this;
                    that.panBotLoading = true;
                    axios.post('/admin/pan_bot/getConfig', Object.assign({}, PostBase))
                        .then(function (response) {
                            that.panBotLoading = false;
                            if (response.data.code == CODE_SUCCESS) {
                                var row = response.data.data || {};
                                that.panBotConfig = row;
                                that.panBotForm = {
                                    base_url: row.base_url || '',
                                    api_key: row.api_key || '',
                                    interval_minutes: parseInt(row.interval_minutes) || 0
                                };
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.panBotLoading = false;
                            var detail = (error && error.response && error.response.data && error.response.data.message) || (error && error.message) || '服务器内部错误';
                            that.$message.error('加载 PanBot 配置失败：' + detail);
                            console.log(error);
                        });
                },
                // 测试连接
                testPanBot() {
                    var that = this;
                    if (!that.panBotForm.base_url || !that.panBotForm.base_url.trim()) {
                        that.$message.error('请填写服务地址');
                        return;
                    }
                    if (!that.panBotForm.api_key || !that.panBotForm.api_key.trim()) {
                        that.$message.error('请填写 API Key');
                        return;
                    }
                    that.panBotTesting = true;
                    axios.post('/admin/pan_bot/testConnection', Object.assign({}, PostBase, {
                        base_url: that.panBotForm.base_url,
                        api_key: that.panBotForm.api_key
                    })).then(function (response) {
                        that.panBotTesting = false;
                        if (response.data.code == CODE_SUCCESS) {
                            that.$message.success(response.data.message);
                        } else {
                            that.$message.error(response.data.message);
                        }
                    }).catch(function (error) {
                        that.panBotTesting = false;
                        that.$message.error('服务器内部错误');
                        console.log(error);
                    });
                },
                // 全量重拉：重置勾选分类同步水位后立即重新拉取勾选分类的全部资源（未勾选分类不会拉取）
                panBotFullResync() {
                    var that = this;
                    that.$confirm('将重置勾选分类的同步水位，并重新拉取勾选分类下的全部资源（未勾选分类不会拉取），确认执行？', '全量重拉', {
                        confirmButtonText: '确定',
                        cancelButtonText: '取消',
                        type: 'warning'
                    }).then(function () {
                        that.panBotResyncing = true;
                        axios.post('/admin/pan_bot/resetSyncTime', Object.assign({}, PostBase))
                            .then(function (response) {
                                if (response.data.code != CODE_SUCCESS) {
                                    that.panBotResyncing = false;
                                    that.$message.error(response.data.message);
                                    return;
                                }
                                return axios.post('/admin/pan_bot/syncAll', Object.assign({}, PostBase));
                            })
                            .then(function (response) {
                                that.panBotResyncing = false;
                                if (!response) return;
                                if (response.data.code == CODE_SUCCESS) {
                                    that.$message.success(response.data.message);
                                    that.getPanBotRecent();
                                    that.getList();
                                } else {
                                    that.$message.error(response.data.message);
                                }
                            })
                            .catch(function (error) {
                                that.panBotResyncing = false;
                                var detail = (error && error.response && error.response.data && error.response.data.message) || (error && error.message) || '服务器内部错误';
                                that.$message.error('全量重拉失败：' + detail);
                                console.log(error);
                            });
                    }).catch(function () {});
                },
                // 保存配置：服务端立即跑一次全量，后续由 cron 自动增量
                savePanBot() {
                    var that = this;
                    if (!that.panBotForm.base_url || !that.panBotForm.base_url.trim()) {
                        that.$message.error('请填写服务地址');
                        return;
                    }
                    if (!that.panBotForm.api_key || !that.panBotForm.api_key.trim()) {
                        that.$message.error('请填写 API Key');
                        return;
                    }
                    that.panBotSaving = true;
                    axios.post('/admin/pan_bot/saveConfig', Object.assign({}, PostBase, that.panBotForm))
                        .then(function (response) {
                            that.panBotSaving = false;
                            if (response.data.code == CODE_SUCCESS) {
                                that.$message.success(response.data.message);
                                // 刷新配置 + 最近同步资源 + 主资源列表
                                that.getPanBotConfig();
                                that.getPanBotRecent();
                                that.getList();
                            } else {
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.panBotSaving = false;
                            that.$message.error('服务器内部错误');
                            console.log(error);
                        });
                },
                // 拉取最近实际同步到 qf_source 的资源
                getPanBotRecent() {
                    var that = this;
                    axios.post('/admin/pan_bot/getRecentResources', Object.assign({}, PostBase))
                        .then(function (response) {
                            if (response.data.code == CODE_SUCCESS) {
                                that.panBotRecent = response.data.data || { list: [], total: 0, since: 0 };
                            } else {
                                that.panBotRecent = { list: [], total: 0, since: 0 };
                            }
                        })
                        .catch(function (error) {
                            that.panBotRecent = { list: [], total: 0, since: 0 };
                            console.log(error);
                        });
                },
                // ==================== panbot 同步分类勾选 ====================
                // 拉取同步大类勾选列表（一级大类=资源库：library_id / library_name / is_sync / category_total）
                getPanBotCategories() {
                    var that = this;
                    that.panBotCatsLoading = true;
                    axios.post('/admin/pan_bot/getCategories', Object.assign({}, PostBase))
                        .then(function (response) {
                            that.panBotCatsLoading = false;
                            if (response.data.code == CODE_SUCCESS) {
                                that.panBotCategories = (response.data.data && response.data.data.list) || [];
                                // 回显已勾选大类
                                that.panBotCatSelected = [];
                                that.panBotCategories.forEach(function (cat) {
                                    if (parseInt(cat.is_sync) === 1) {
                                        that.panBotCatSelected.push(String(cat.library_id));
                                    }
                                });
                            } else {
                                that.panBotCategories = [];
                                that.panBotCatSelected = [];
                                that.$message.error(response.data.message);
                            }
                        })
                        .catch(function (error) {
                            that.panBotCatsLoading = false;
                            that.panBotCategories = [];
                            that.panBotCatSelected = [];
                            console.log(error);
                        });
                },
                // 勾选变化 500ms 防抖后保存（连续点击只提交最终勾选状态）
                onPanBotCatChange() {
                    var that = this;
                    if (that.panBotCatTimer) {
                        clearTimeout(that.panBotCatTimer);
                    }
                    that.panBotCatTimer = setTimeout(function () {
                        that.panBotCatsSaving = true;
                        axios.post('/admin/pan_bot/saveCategories', Object.assign({}, PostBase, {
                            library_ids: that.panBotCatSelected || []
                        }))
                            .then(function (response) {
                                that.panBotCatsSaving = false;
                                if (response.data.code == CODE_SUCCESS) {
                                    that.$message.success(response.data.message);
                                } else {
                                    that.$message.error(response.data.message);
                                }
                            })
                            .catch(function (error) {
                                that.panBotCatsSaving = false;
                                that.$message.error('服务器内部错误');
                                console.log(error);
                            });
                    }, 500);
                },
                // 全选 / 全不选
                panBotCatToggleAll(check) {
                    var selected = [];
                    if (check) {
                        this.panBotCategories.forEach(function (cat) {
                            selected.push(String(cat.library_id));
                        });
                    }
                    this.panBotCatSelected = selected;
                    this.onPanBotCatChange();
                },
                // 通过本地分类 id 反查名称
                findLocalCategoryName(id) {
                    if (!id) return '';
                    var list = this.category || [];
                    for (var i = 0; i < list.length; i++) {
                        if (parseInt(list[i].source_category_id) === parseInt(id)) {
                            return list[i].name;
                        }
                    }
                    return '';
                },
                // 时间戳格式化
                formatPanBotTime(ts) {
                    if (!ts || ts == 0) return '';
                    var d = new Date(parseInt(ts) * 1000);
                    var p = function (n) { return n < 10 ? '0' + n : n; };
                    return (d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
                },
            }
        })
