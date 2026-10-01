var app = new Vue({
    el: '#app',
    data() {
        this.getList();
        this.getCategoryList();
        this.getSyncKey();
        return {
            loading: true,
            dataList: [],
            categoryList: [],
            // 资源同步密钥（本站作为源站时校验他站拉取请求）
            syncKey: '',
            syncKeySaving: false,
            dialogForm: false,
            dialogTitle: '',
            isEdit: false,
            formData: this.getEmptyForm(),
            rules: {
                name: [{ required: true, message: '请输入服务器名称', trigger: 'blur' }],
                domain: [{ required: true, message: '请输入源站域名', trigger: 'blur' }],
                api_key: [{ required: true, message: '请输入同步密钥', trigger: 'blur' }],
            },
            // 同步进度
            syncDialog: false,
            syncing: false,
            syncServerName: '',
            syncResult: { total: 0, new_added: 0, updated: 0, skipped: 0, failed: 0 },
            // 日志
            logDialog: false,
            logLoading: false,
            logList: [],
        }
    },
    methods: {
        getEmptyForm() {
            return {
                sync_server_id: 0,
                name: '',
                domain: '',
                api_key: '',
                status: 1,
                default_category_id: 0,
                category_map: '',
                sync_is_type_arr: [],
                sync_is_type: '',
                auto_sync: 0,
                sync_hour: 2,
                remark: '',
            };
        },

        getList() {
            var that = this;
            that.loading = true;
            axios.post('/admin/sync_server/getList', Object.assign({}, PostBase))
                .then(function (response) {
                    that.loading = false;
                    if (response.data.code == CODE_SUCCESS) {
                        that.dataList = response.data.data || [];
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

        getCategoryList() {
            var that = this;
            axios.post('/admin/sync_server/getCategoryList', Object.assign({}, PostBase))
                .then(function (response) {
                    if (response.data.code == CODE_SUCCESS) {
                        that.categoryList = response.data.data || [];
                    }
                })
                .catch(function (error) {
                    console.log(error);
                });
        },

        getSyncKey() {
            var that = this;
            axios.post('/admin/sync_server/getSyncKey', Object.assign({}, PostBase))
                .then(function (response) {
                    if (response.data.code == CODE_SUCCESS) {
                        that.syncKey = (response.data.data && response.data.data.sync_key) || '';
                    }
                })
                .catch(function (error) {
                    console.log(error);
                });
        },

        saveSyncKey() {
            var that = this;
            var key = (that.syncKey || '').trim();
            if (key && key.length < 8) {
                that.$message.warning('同步密钥至少需要 8 个字符');
                return;
            }
            that.syncKeySaving = true;
            axios.post('/admin/sync_server/saveSyncKey', Object.assign({}, PostBase, {
                sync_key: key
            }))
                .then(function (response) {
                    that.syncKeySaving = false;
                    if (response.data.code == CODE_SUCCESS) {
                        that.$message.success(response.data.message);
                    } else {
                        that.$message.error(response.data.message);
                    }
                })
                .catch(function (error) {
                    that.syncKeySaving = false;
                    that.$message.error('服务器内部错误');
                    console.log(error);
                });
        },

        generateSyncKey() {
            var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var key = '';
            for (var i = 0; i < 24; i++) {
                key += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            this.syncKey = key;
            this.$message.success('已生成随机密钥，请点击保存按钮生效');
        },

        clickAdd() {
            this.isEdit = false;
            this.dialogTitle = '添加同步服务器';
            this.formData = this.getEmptyForm();
            this.dialogForm = true;
        },

        clickEdit(row) {
            var that = this;
            that.isEdit = true;
            that.dialogTitle = '编辑同步服务器';
            axios.post('/admin/sync_server/detail', Object.assign({}, PostBase, {
                sync_server_id: row.sync_server_id
            }))
                .then(function (response) {
                    if (response.data.code == CODE_SUCCESS) {
                        var data = response.data.data;
                        data.sync_is_type_arr = data.sync_is_type ? data.sync_is_type.split(',') : [];
                        that.formData = data;
                        that.dialogForm = true;
                    } else {
                        that.$message.error(response.data.message);
                    }
                })
                .catch(function (error) {
                    that.$message.error('服务器内部错误');
                    console.log(error);
                });
        },

        postSave() {
            var that = this;
            that.$refs['formData'].validate((valid) => {
                if (valid) {
                    var postData = Object.assign({}, that.formData);
                    postData.sync_is_type = (postData.sync_is_type_arr || []).join(',');
                    delete postData.sync_is_type_arr;

                    var url = that.isEdit ? '/admin/sync_server/update' : '/admin/sync_server/add';
                    axios.post(url, Object.assign({}, PostBase, postData))
                        .then(function (response) {
                            if (response.data.code == CODE_SUCCESS) {
                                that.$message.success(response.data.message);
                                that.dialogForm = false;
                                that.getList();
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

        clickTest() {
            var that = this;
            if (!that.formData.domain || !that.formData.api_key) {
                that.$message.warning('请先填写域名和密钥');
                return;
            }
            axios.post('/admin/sync_server/test', Object.assign({}, PostBase, {
                domain: that.formData.domain,
                api_key: that.formData.api_key,
            }))
                .then(function (response) {
                    if (response.data.code == CODE_SUCCESS) {
                        that.$message.success('连接成功：' + (response.data.data.site_name || ''));
                    } else {
                        that.$message.error(response.data.message);
                    }
                })
                .catch(function (error) {
                    that.$message.error('请求失败');
                    console.log(error);
                });
        },

        clickSync(row) {
            var that = this;
            that.$confirm('确认从「' + row.name + '」同步资源？同步过程可能需要几分钟', '同步确认', {
                confirmButtonText: '开始同步',
                cancelButtonText: '取消',
                type: 'info'
            }).then(() => {
                that.syncServerName = row.name;
                that.syncing = true;
                that.syncDialog = true;
                that.syncResult = { total: 0, new_added: 0, updated: 0, skipped: 0, failed: 0 };

                axios.post('/admin/sync_server/sync', Object.assign({}, PostBase, {
                    sync_server_id: row.sync_server_id
                }))
                    .then(function (response) {
                        that.syncing = false;
                        if (response.data.code == CODE_SUCCESS) {
                            that.syncResult = response.data.data || that.syncResult;
                            that.getList();
                        } else {
                            that.syncResult = { total: 0, new_added: 0, updated: 0, skipped: 0, failed: 1 };
                            that.$message.error(response.data.message);
                        }
                    })
                    .catch(function (error) {
                        that.syncing = false;
                        that.syncResult = { total: 0, new_added: 0, updated: 0, skipped: 0, failed: 1 };
                        that.$message.error('同步请求失败');
                        console.log(error);
                    });
            }).catch(() => {});
        },

        clickLogs() {
            var that = this;
            that.logDialog = true;
            that.logLoading = true;
            axios.post('/admin/sync_server/logs', Object.assign({}, PostBase))
                .then(function (response) {
                    that.logLoading = false;
                    if (response.data.code == CODE_SUCCESS) {
                        that.logList = response.data.data || [];
                    } else {
                        that.$message.error(response.data.message);
                    }
                })
                .catch(function (error) {
                    that.logLoading = false;
                    that.$message.error('服务器内部错误');
                    console.log(error);
                });
        },

        handleCommand(command, row) {
            var that = this;
            switch (command) {
                case 'test':
                    that.testConnection(row);
                    break;
                case 'reset':
                    that.resetSyncTime(row);
                    break;
                case 'status':
                    that.toggleStatus(row);
                    break;
                case 'delete':
                    that.clickDelete(row);
                    break;
            }
        },

        testConnection(row) {
            var that = this;
            axios.post('/admin/sync_server/test', Object.assign({}, PostBase, {
                domain: row.domain,
                api_key: row.api_key,
            }))
                .then(function (response) {
                    if (response.data.code == CODE_SUCCESS) {
                        that.$message.success('连接成功：' + (response.data.data.site_name || ''));
                    } else {
                        that.$message.error(response.data.message);
                    }
                })
                .catch(function (error) {
                    that.$message.error('请求失败');
                    console.log(error);
                });
        },

        resetSyncTime(row) {
            var that = this;
            that.$confirm('重置后下次将全量同步所有资源，是否继续？', '重置确认', {
                confirmButtonText: '重置',
                cancelButtonText: '取消',
                type: 'warning'
            }).then(() => {
                axios.post('/admin/sync_server/resetSyncTime', Object.assign({}, PostBase, {
                    sync_server_id: row.sync_server_id
                }))
                    .then(function (response) {
                        if (response.data.code == CODE_SUCCESS) {
                            that.$message.success(response.data.message);
                            that.getList();
                        } else {
                            that.$message.error(response.data.message);
                        }
                    })
                    .catch(function (error) {
                        that.$message.error('请求失败');
                        console.log(error);
                    });
            }).catch(() => {});
        },

        toggleStatus(row) {
            var that = this;
            var newStatus = row.status == 1 ? 0 : 1;
            axios.post('/admin/sync_server/update', Object.assign({}, PostBase, {
                sync_server_id: row.sync_server_id,
                name: row.name,
                domain: row.domain,
                api_key: row.api_key,
                status: newStatus,
                default_category_id: row.default_category_id,
                category_map: row.category_map || '',
                sync_is_type: row.sync_is_type || '',
                auto_sync: row.auto_sync || 0,
                sync_hour: row.sync_hour ?? 2,
                remark: row.remark || '',
            }))
                .then(function (response) {
                    if (response.data.code == CODE_SUCCESS) {
                        that.$message.success(newStatus == 1 ? '已启用' : '已禁用');
                        that.getList();
                    } else {
                        that.$message.error(response.data.message);
                    }
                })
                .catch(function (error) {
                    that.$message.error('请求失败');
                    console.log(error);
                });
        },

        clickDelete(row) {
            var that = this;
            that.$confirm('即将删除服务器「' + row.name + '」，已同步的资源不会被删除，是否确认？', '删除提醒', {
                confirmButtonText: '删除',
                cancelButtonText: '取消',
                type: 'warning'
            }).then(() => {
                axios.post('/admin/sync_server/delete', Object.assign({}, PostBase, {
                    sync_server_id: row.sync_server_id
                }))
                    .then(function (response) {
                        if (response.data.code == CODE_SUCCESS) {
                            that.$message.success(response.data.message);
                            that.getList();
                        } else {
                            that.$message.error(response.data.message);
                        }
                    })
                    .catch(function (error) {
                        that.$message.error('服务器内部错误');
                        console.log(error);
                    });
            }).catch(() => {});
        },

        formatTime(timestamp) {
            if (!timestamp || timestamp == 0) return '-';
            // 兼容 Unix 时间戳（数字）和日期字符串（如 "2026-07-13 13:00:47"）
            var date;
            if (typeof timestamp === 'number' || /^\d+$/.test(String(timestamp))) {
                date = new Date(Number(timestamp) * 1000);
            } else {
                date = new Date(String(timestamp).replace(/-/g, '/'));
            }
            if (isNaN(date.getTime())) return '-';
            var Y = date.getFullYear();
            var M = String(date.getMonth() + 1).padStart(2, '0');
            var D = String(date.getDate()).padStart(2, '0');
            var h = String(date.getHours()).padStart(2, '0');
            var m = String(date.getMinutes()).padStart(2, '0');
            return Y + '-' + M + '-' + D + ' ' + h + ':' + m;
        },
    }
})
