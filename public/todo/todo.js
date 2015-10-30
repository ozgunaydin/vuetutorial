new Vue({

    el      : '#tasks',
    data    : {

        tasks  : [],
        newTask: ''
    },
    filters : {
        inProcess: function () {
            return this.remaining
        }
    },
    computed: {
        completions: function () {
            return this.tasks.filter(function (task) {
                return task.completed
            })
        },
        remaining  : function () {
            return this.tasks.filter(function (task) {
                return !task.completed
            })
        }
    },
    methods : {
        addTask         : function (e) {
            e.preventDefault()
            if (!this.newTask) return;
            this.tasks.push({
                body     : this.newTask,
                completed: false
            })
            this.newTask = ''
        },
        removeTask      : function (task) {
            this.tasks.$remove(task)
        },
        editTask        : function (task) {
            this.removeTask(task);
            this.newTask = task.body
            this.$$.newTask.focus()
        },
        completeTask    : function (task) {
            task.completed = true
        },
        markAllCompleted: function () {
            this.tasks.forEach(function (task) {
                task.completed = true
            })
        },
        clearCompleted  : function () {
            this.tasks = this.remaining
        }

    }

});