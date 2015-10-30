Vue.filter('reverse', function (value, wordsOnly) {
    if (!wordsOnly) {
        return value;
    }
    var seperator = wordsOnly=='words' ? ' ' : '';
    return value.split(seperator).reverse().join(seperator)
});
new Vue({

    el     : '#demo',
    data   : {
        people     : [
            {'name': 'Celsus Edgaras', 'age': 50},
            {'name': 'Daw Lavrenty', 'age': 23},
            {'name': 'Thomas Orion', 'age': 78},
            {'name': 'Cody Shai', 'age': 12},
            {'name': 'Mazin Jaya', 'age': 42},
            {'name': 'Teddy Mário', 'age': 30},
        ],
        sortKey    : '',
        reverse    : 1,
        search     : '',
        age        : 'all',
        reverseGame: ''
    },
    filters: {
        byAge: function (people) {
            if (this.age == 'all') {
                return people;
            }
            return people.filter(function (person) {
                return (this.age == 'above') ? (person.age >= 50) : (person.age < 50);
            }.bind(this));
        }
    },
    methods: {
        sortBy  : function (sortKey) {
            this.reverse = (this.sortKey == sortKey) ? -this.reverse : 1;
            this.sortKey = sortKey;
        },
        isActive: function (sortKey) {
            return (this.sortKey == sortKey);
        }
    }

});


