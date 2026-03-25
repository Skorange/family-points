/**
 * API 调用层
 */
const API_BASE = 'api';

const Api = {
    // 存储 token
    token: localStorage.getItem('token') || null,
    user: JSON.parse(localStorage.getItem('user') || 'null'),

    // 获取请求头
    getHeaders() {
        const headers = {
            'Content-Type': 'application/json'
        };
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }
        return headers;
    },

    // 通用请求方法
    async request(endpoint, options = {}) {
        const url = `${API_BASE}/${endpoint}`;
        const config = {
            ...options,
            headers: {
                ...this.getHeaders(),
                ...options.headers
            }
        };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || '请求失败');
            }

            return data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    // 认证相关
    auth: {
        // 通过成员ID登录（家庭名+成员名+密码）
        async loginByMember(memberId, password) {
            const data = await Api.request('auth.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'login',
                    member_id: memberId,
                    password
                })
            });
            Api.setAuth(data.token, data.user);
            return data;
        },

        // 初始化：创建家庭并注册第一个成员
        async setupFamily(data) {
            const result = await Api.request('auth.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'setup',
                    member_id: data.member_id,
                    member_name: data.member_name,
                    role: data.role,
                    password: data.password
                })
            });
            Api.setAuth(result.token, result.user);
            return result;
        },

        // 家长创建成员
        async createChild(username, password) {
            return Api.request('auth.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'create_child',
                    username,
                    password
                })
            });
        },

        // 家长创建家长账号
        async createParent(username, password) {
            return Api.request('auth.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'create_parent',
                    username,
                    password
                })
            });
        },

        // 获取当前用户资料
        async getProfile() {
            return Api.request('auth.php');
        }
    },

    // 任务相关
    tasks: {
        async list() {
            return Api.request('tasks.php');
        },

        async create(task) {
            return Api.request('tasks.php', {
                method: 'POST',
                body: JSON.stringify(task)
            });
        },

        async complete(taskId) {
            return Api.request('tasks.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'complete',
                    task_id: taskId
                })
            });
        },

        async update(task) {
            return Api.request('tasks.php', {
                method: 'PUT',
                body: JSON.stringify(task)
            });
        },

        async delete(taskId) {
            return Api.request(`tasks.php?id=${taskId}`, {
                method: 'DELETE'
            });
        }
    },

    // 积分相关
    points: {
        async history() {
            return Api.request('points.php');
        },

        async ranking() {
            return Api.request('points.php?type=rank');
        },

        async adjust(userId, amount, type, note) {
            return Api.request('points.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'adjust',
                    user_id: userId,
                    amount,
                    type,
                    note
                })
            });
        }
    },

    // 奖励相关
    rewards: {
        async list() {
            return Api.request('rewards.php');
        },

        async create(reward) {
            return Api.request('points.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'add_reward',
                    ...reward
                })
            });
        },

        async redeem(rewardId) {
            return Api.request('points.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'redeem',
                    reward_id: rewardId
                })
            });
        },

        async approve(redemptionId, approvalAction = 'approve') {
            return Api.request('points.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'approve_redemption',
                    redemption_id: redemptionId,
                    approval_action: approvalAction
                })
            });
        },

        async pendingApprovals() {
            return Api.request('rewards.php?type=pending');
        },

        async redemptionHistory() {
            return Api.request('rewards.php?type=history');
        }
    },

    // 家庭日历事件
    events: {
        async list(year, month) {
            const y = year || new Date().getFullYear();
            const m = month || (new Date().getMonth() + 1);
            return Api.request('events.php?year=' + y + '&month=' + m);
        },
        async create(data) {
            return Api.request('events.php', {
                method: 'POST',
                body: JSON.stringify(data)
            });
        },
        async update(data) {
            return Api.request('events.php', {
                method: 'PUT',
                body: JSON.stringify(data)
            });
        },
        async delete(id) {
            return Api.request('events.php?id=' + id, {
                method: 'DELETE'
            });
        }
    },

    // 家庭公告
    announce: {
        async list() {
            return Api.request('announce.php');
        },
        async create(data) {
            return Api.request('announce.php', {
                method: 'POST',
                body: JSON.stringify(data)
            });
        },
        async update(data) {
            return Api.request('announce.php', {
                method: 'PUT',
                body: JSON.stringify(data)
            });
        },
        async delete(id) {
            return Api.request('announce.php?id=' + id, {
                method: 'DELETE'
            });
        }
    },

    // 家庭资料
    family: {
        async list(category = '') {
            const url = category ? `family.php?category=${category}` : 'family.php';
            return Api.request(url);
        },

        async create(info) {
            return Api.request('family.php', {
                method: 'POST',
                body: JSON.stringify(info)
            });
        },

        async update(info) {
            return Api.request('family.php', {
                method: 'PUT',
                body: JSON.stringify(info)
            });
        },

        async delete(infoId) {
            return Api.request(`family.php?id=${infoId}`, {
                method: 'DELETE'
            });
        },

        // 更新成员（爸爸专属）
        async updateMember(memberId, username, password) {
            return Api.request('family.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'update_member',
                    member_id: memberId,
                    username,
                    password
                })
            });
        },

        // 删除成员（爸爸专属）
        async deleteMember(memberId) {
            return Api.request('family.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'delete_member',
                    member_id: memberId
                })
            });
        },

        // 快速添加生日（爸妈）
        async addBirthday(title, content) {
            return Api.request('family.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'add_birthday',
                    title,
                    content
                })
            });
        }
    },

    // 设置认证信息
    setAuth(token, user) {
        this.token = token;
        this.user = user;
        localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(user));
    },

    // 清除认证
    logout() {
        this.token = null;
        this.user = null;
        localStorage.removeItem('token');
        localStorage.removeItem('user');
    },

    // 检查是否已登录
    isLoggedIn() {
        return !!this.token && !!this.user;
    },

    // 检查是否是家长
    isParent() {
        return this.user?.role === 'parent';
    },

    // 权限判断（基于 member_id）
    isDad() {
        return this.user?.member_id === 'p_dad';
    },

    // 爸妈可操作（创建任务/奖励/审批/公告编辑等）
    canOperate() {
        const mid = this.user?.member_id;
        return mid === 'p_dad' || mid === 'p_mom';
    },

    // 爸爸专属：成员账号管理
    canManageMembers() {
        return this.user?.member_id === 'p_dad';
    },

    // 是否为孩子
    isChild() {
        return this.user?.role === 'child';
    }
};
