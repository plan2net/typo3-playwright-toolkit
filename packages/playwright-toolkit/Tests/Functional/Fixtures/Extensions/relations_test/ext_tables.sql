CREATE TABLE tx_relationstest_item (
    title varchar(255) DEFAULT '' NOT NULL,
    image int(11) unsigned DEFAULT '0' NOT NULL,
    parentid int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content (
    tx_relationstest_items int(11) unsigned DEFAULT '0' NOT NULL
);
