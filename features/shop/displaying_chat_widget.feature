@agent001_chat_widget
Feature: Displaying the Agent001 chat widget
    In order to get help while shopping
    As a Visitor
    I want to talk to the store assistant on the storefront

    Background:
        Given the store operates on a single channel in "United States"
        And the store is connected with Agent001
        And the "United States" channel has an Agent001 agent assigned

    @ui
    Scenario: Seeing the chat widget once the catalog is synchronized
        Given its first catalog synchronization has finished
        When I open the storefront
        Then I should see the Agent001 chat widget

    @ui
    Scenario: Not seeing the chat widget before the first synchronization finishes
        When I open the storefront
        Then I should not see the Agent001 chat widget

    @ui
    Scenario: Not seeing the chat widget while the agent is disabled
        Given its first catalog synchronization has finished
        And its Agent001 agent has been disabled
        When I open the storefront
        Then I should not see the Agent001 chat widget
